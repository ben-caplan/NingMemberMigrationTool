<?php
	/**
	 * Output syntax for command
	 */
	WP_CLI::add_command( 'gvlmTbl', 'GVLM_Govloop_To_SQL_Migrator' );




	/**
	 * GVLM CLASS
	 */
	class GVLM_Govloop_To_SQL_Migrator extends WP_CLI_Command {
		//LOCAL VARS
		private $startTime,
				$startBracketLocation = 0,
				$numRecordsAdded = 0,
				$numberRecords = 0,
				$badRecords = 0,
				$numMembers = 0,
				$run = true,
				$isGoodJson = true,
				$stillBroken = false,
				$badJSON = '',
				$memberArray = array(),
				$fieldDataModel = array(
					"createdDate",
					"fullName",
					"gender",
					"location",
					"country",
					"zip",
					"birthdate",
					"email",
					"profileQuestions" => array(
						'q1' => "Type of Government",
						'q2' => "Current Title:",
						'q3' => "Current Agency or Organization",
						'q4' => "What Best Describes Your Role?",
						'q5' => "Educational Background (Degree, School):",
						'q6' => "Profile Links - Blog, Twitter, LinkedIn, Facebook",
						'q7' => "Topics I Care About",
						'q8' => "Are you involved (buy, recommend, influence) in the purchase of:",
						'q9' => "How Did You Hear About GovLoop?",
						'q10' => "I work for the government because:",
						'q11' => "My favorite public servant is:"
					),
					"profilePhoto", 
					"level",
					"state",
					"contributorName"
				);



		//PARSE JSON STRING
		private function parseJsonString( $jsonStringToParse, $i, $tryNum=1 ){
			global $wpdb;
			$dbPrefix = $wpdb->base_prefix;
			$jsonItem = json_decode( $jsonStringToParse, true );
			$requiredFields = array( "email", "contributorName", "fullName", "createdDate", "fullName", "gender", "location", "country", "zip", "birthdate" );
			if( !empty($jsonItem) ){
				$dataArray = array();
				$user = $wpdb->get_row("SELECT contributorName FROM {$dbPrefix}temp_members WHERE contributorName='{$jsonItem['contributorName']}'");
				$timeStamp = date('Y-m-d H:i:s');

				//build dataArray
				foreach( $jsonItem as $fieldKey=>$fieldValue ){
					if( in_array($fieldKey, $this->fieldDataModel) ){
						//build dataArray
						if( !empty($fieldValue) && is_array($fieldValue) ){
							if( $fieldKey !== 'comments' ){
								foreach( $fieldValue as $subFieldKey=>$subFieldValue ){
									$dbKey = array_search($subFieldKey, $this->fieldDataModel['profileQuestions']);
									if( $dbKey ){
										$dataArray[$dbKey] = $subFieldValue;
									}
								}
							}
						}//is_array($fieldValue)
						else $dataArray[$fieldKey] = $fieldValue;
					}//in_array($fieldKey, $this->fieldDataModel)
				}//foreach $jsonItem

				
				//WHICH FIELDS ARE MISSING?
				foreach( $requiredFields as $field ){
					$warningArray = array();
					if( empty($dataArray[$field]) ) $warningArray[] = $field;
				}
				if( !empty($warningArray) ) WP_CLI::warning("PROFILE DATA: (starting at char #{$this->startBracketLocation}) User " . $dataArray['contributorName'] . ' does not have the following data: ' .implode(', ', $warningArray));


				//ADD DATA TO DATABASE
				if( empty($user) ){
					//NEW RECORD
					//add data to database
					$dataAdded = $wpdb->insert(
						$dbPrefix.'temp_members',
						$dataArray
					);

					//output feedback
					if( $dataAdded ){
						WP_CLI::success("{$timeStamp}: Record added! (starting at char #{$this->startBracketLocation})");
						$this->stillBroken = false;
						//add member
						$this->numMembers++;
						$this->numberRecords++;
						$this->numRecordsAdded++;
					}
					else WP_CLI::warning("PROFILE DATA: upload failed. Something went wrong with the upload (starting at char #{$this->startBracketLocation}).");
				}//empty($user)
				else{
					//UPDATE RECORD?
					WP_CLI::warning("PROFILE DATA: {$timeStamp}: (starting at char #{$this->startBracketLocation}) request to update user {$jsonItem['contributorName']}");
					$this->numMembers++;
				}//!empty($user)
			}//!empty($jsonItem)
			else{ 
				//IF BAD RECORD RERUN CODE - try to fix JSON
				$updatedJson = $jsonStringToParse;
				if( $this->isGoodJson === true ){
					//REGEX PATTERNS
					$pattern1 = '/("[a-zA-Z0-9][^\{\}\[\]"]*)(\{|\})([^\{\}\[\]"]*")/';
					$pattern2 = '/"}(,"createdDate")/';
					//SPECIFIC ISSUES - fix attempts (more specialized then the larger fixes in updateTable())
					switch($tryNum){
						case 1:
							$updatedJson = preg_replace('/\n/',' ',$updatedJson);//fix line breaks
							break;
						case 2:
							//fix { or } in middle of string
							$updatedJson = preg_replace($pattern1,"$1 $3",$jsonStringToParse);
							break;
						case 3: 
							//fix broken comment(s): "},"createdDate"
							if( preg_match($pattern1, $jsonStringToParse) ) $updatedJson = preg_replace('/"}(,"createdDate")/', '"$1', $updatedJson);
							$updatedJson = preg_replace($pattern2, '"$1', $updatedJson);
							break;
						case 4:
							$updatedJson = preg_replace('/\}"/','"}',$updatedJson);
							$this->isGoodJson = false;
							break;
					}
					//run updated string
					$tryNum++;
					$this->parseJsonString( $updatedJson, $i , $tryNum);
				}
				else{
					$this->badRecords++;
					$this->isGoodJson = true;
					$this->run = false;
					//add member
					$this->numMembers++;
					$this->numberRecords++;
					
					//BAD JSON :-(
					$this->badJSON = $updatedJson;
					WP_CLI::warning("JSON ERROR: bad JSON (starting at char #{$this->startBracketLocation})\n");
				}
			}
			return true;
		}//parseJsonString()



		//UPDATE TABLE
		function updateTable($iArr, $aArr){
			//CHECK THAT JSON FILE HAS BEEN PROVIDED - if not throw an error
			if( !empty($iArr[0]) && !file_exists(dirname(__FILE__)."/../json/{$iArr[0]}" ) ) WP_CLI::error( "Migration Failed - JSON file not found" );

			global $wpdb;
			$dbPrefix = $wpdb->base_prefix;
			$json = file_get_contents(dirname(__FILE__)."/../json/{$iArr[0]}");

			//FIX "BAD" JSON
			$json = preg_replace('/((;|:)-?)\}/', '$1&#125;', $json);// fix :-}
			$json = preg_replace('/\]\{/',',{',$json);//fix ]{ -> ,{
			$json = preg_replace('/\}( |\n)?\{/','},{',$json);//fix }{ -> },{
			$json = preg_replace('/\}\]\{/','},{',$json);//fix }]{ -> },{

			//LOOPING VARS
			$startChar = 1;
			$stopChar = strlen($json);
			$numBrackets = 0;
			$this->startTime = date('Y-m-d H:i:s');
			//$numRecordsToRecord = 400,
			//$numCharsToRecord = 85825108,

			//RUN THE LOOP
			for($i=$startChar; $i<$stopChar; $i++){
				if( !empty($numRecordsToRecord) && $numRecordsToRecord === $this->numberRecords) break;
				if( !empty($numCharsToRecord) && $numCharsToRecord === $i) break;

				if( $numBrackets === 0 && $json[$i] == ',' ){
					$this->parseJsonString( $this->memberArray[$this->numMembers], $i );
					if( $this->run === false ) break;
				}//$numBrackets === 0 && $json[$i] == ','
				else{
					if( $numBrackets === 0 && $json[$i] == '{' ) $this->startBracketLocation = $i;
					$this->memberArray[$this->numMembers] = empty($this->memberArray[$this->numMembers]) ? $json[$i] : $this->memberArray[$this->numMembers] . $json[$i];
				}

			    if( $json[$i] == "{" ) $numBrackets++;
			    if( $json[$i] == "}" ) $numBrackets--;
			}//for loop
			//PRINT RESULTS
			echo "\n\n\n==========================\n::SUMMARY::\n==========================\n";
			echo "{$stopChar} characters read.\n";
			echo "There were {$this->numberRecords} TOTAL records!\n";
			echo "There were {$this->numRecordsAdded} ADDED records!\n";
			echo "There were {$this->badRecords} BAD records!\n";
			echo "Run started at {$this->startTime}\n";
			echo "Run ended at ".date('Y-m-d H:i:s')."\n";
			if( !empty($this->badJSON) ){
				echo "\n==========================\n";
				echo "\n==============================================================================================\n";
				echo "::BAD JSON::\n";
				echo "\n==============================================================================================\n";
				echo $this->badJSON;
				echo "\n==============================================================================================\n";
			}
		}//addProfileData()



		/**
		 * OUTPUT SYNTAX FOR COMMAND
		 */
		public static function help() {
			WP_CLI::line( "usage: wp gvlmTbl updateTable [path/to/json/file]" );
		}
	}
