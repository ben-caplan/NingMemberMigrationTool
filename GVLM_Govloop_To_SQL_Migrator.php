<?php
	/*
		TODO:
		- build script - create profile field in DB dynamically
		- Sanatize DB writes
		- make $fieldDataModel dynamic
		- clean up script - remove usermeta?
		- make error messages more useful
		- refactor to tidy up
	*/

	/**
	 * Output syntax for command
	 */
	WP_CLI::add_command( 'gvlmTbl', 'GVLM_Govloop_To_SQL_Migrator' );




	/**
	 * GVLM CLASS
	 */
	class GVLM_Govloop_To_SQL_Migrator extends WP_CLI_Command {
		//LOCAL VARS
		private $count = 0,
				$startTime,
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
		function parseJsonString( $jsonStringToParse, $i, $tryNum=1 ){
			echo "\n\n*************" . substr($jsonStringToParse, 0, 100) . "*************\n\n";
			global $wpdb;
			$dbPrefix = $wpdb->base_prefix;
			$jsonItem = json_decode( $jsonStringToParse, true );

			if( !empty($jsonItem) ){
				$dataArray = array();
				$user = $wpdb->get_row("SELECT uid FROM {$dbPrefix}temp_members WHERE contributorName='{$jsonItem['contributorName']}'");
				
				if( empty($user) ){
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

					//add data to database
					$dataAdded = $wpdb->insert(
						$dbPrefix.'temp_members',
						$dataArray
					);

					//output feedback
					if( $dataAdded ){
						$timeStamp = date('Y-m-d H:i:s');
						WP_CLI::success("{$timeStamp}: Record added!");
						$this->count++;
						$this->numRecordsAdded++;
						$this->stillBroken = false;
					}
					else WP_CLI::warning('PROFILE DATA: upload failed. Something went wrong with the upload.');
				}//empty($user)
				else WP_CLI::warning('PROFILE DATA: User '.$jsonItem['contributorName'].' already exists.');
			}//!empty($jsonItem)
			else{ 
				//IF BAD RECORD RERUN CODE - try to fix JSON - only try to fix once (prevent infinite loop...)
				$updatedJson = $jsonStringToParse;
				if( $this->isGoodJson === true ){
					//fix { or } in middle of string
					switch($tryNum){
						case 1: 
							echo 'case 1';
							$updatedJson = preg_replace('/("[a-zA-Z0-9][^\{\}\[\]"]*)(\{|\})([^\{\}\[\]"]*")/',"$1 $3",$jsonStringToParse);
							break;
						case 2: 
							echo 'case 2';
							$updatedJson = preg_replace('/("description": ?".*"),?\n?[^\}"]*\},?/',"$1",$jsonStringToParse);//fix broken comments
							$this->isGoodJson = false;
							break;
						case 3: 
							echo 'case 3';
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
				}
				$this->badJSON = $updatedJson;
				
				WP_CLI::warning("JSON ERROR: bad JSON (starting at char #{$this->startBracketLocation})\n");
			}

			//add member
			$this->numMembers++;
			$this->numberRecords++;

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
			$json = preg_replace('/\}"/','"}',$json);//fix }"
			$json = preg_replace('/\}\{/','},{',$json);//fix }{ 
			$json = preg_replace('/\n/',' ',$json);//fix line breaks
			$json = preg_replace('/\]\{/',',{',$json);//fix ]{

			//LOOPING VARS
			$startChar = 84964120;//1;
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
