<?php
	/*
		NEED TO DO:
		- update map to BP bp_xprofile_data (indexes of profileQuestions array)
		- update comments logic

		private
			-> generatePassword( $length )
			-> parseJsonString( $jsonStringToParse, $characterPosition, $tryNumber=1 )
			-> 
	*/

	/**
	 * Output syntax for command
	 */
	WP_CLI::add_command( 'gvlm', 'GVLM_Govloop_Migrator' );




	/**
	 * GVLM CLASS
	 */
	class GVLM_Govloop_Migrator extends WP_CLI_Command {
		//LOCAL VARS
		private $user = null, 
				$startTime,
				$startBracketLocation = 0,
				$numRecordsAdded = 0,
				$numberRecords = 0,
				$incompleteRecords = 0,
				$incompleteRecordsDups = 0,
				$numComments = 0,
				$badRecords = 0,
				$numMembers = 0,
				$run = true,
				$isGoodJson = true,
				$stillBroken = false,
				$badJSON = '',
				$memberArray = array(),
				$fieldDataModel = array(
					"email",
					"contributorName",
					"createdDate",
					"fullName",
					"gender",
					"location",
					"country",
					"zip",
					"birthdate",
					"profileQuestions" => array(
						//1 => 'Full Name'
						'2' => "Type of Government",
						'3' => "Current Title:",
						'4' => "Current Agency or Organization",
						'5' => "What Best Describes Your Role?",
						'6' => "Educational Background (Degree, School):",
						'7' => "Profile Links - Blog, Twitter, LinkedIn, Facebook",
						'8' => "Topics I Care About",
						'9' => "Are you involved (buy, recommend, influence) in the purchase of:",
						'10' => "How Did You Hear About GovLoop?",
						'11' => "I work for the government because:",
						'12' => "My favorite public servant is:"
					),
					"profilePhoto"
				);

		//GENERAT PASSWORD
		private function generatePassword( $length ){
			$chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_-=+;:,.?";
			$password = substr( str_shuffle( $chars ), 0, $length );
			return $password;
		}//generatePassword()


		//PARSE JSON STRING
		private function parseJsonString( $jsonStringToParse, $i, $action, $tryNum=1 ){
			global $wpdb;
			$dbPrefix = $wpdb->base_prefix;
			$jsonItem = json_decode( $jsonStringToParse, true );
			$requiredFields = array( "email", "contributorName", "fullName", "createdDate", "fullName", "gender", "location", "country", "zip", "birthdate" );
			if( !empty($jsonItem) ){
				$dataArray = array( 'profile_data' => array() );
				$user = $wpdb->get_row("SELECT wp_id FROM {$dbPrefix}ning_user_lookup WHERE ning_id='{$jsonItem['contributorName']}'");
				$timeStamp = date('Y-m-d H:i:s');


				//ADD USER
				if( $action === 'users' ){
					//WHICH FIELDS ARE MISSING?
					$warningArray = array();
					foreach( $requiredFields as $field ){
						if( empty($jsonItem[$field]) ) $warningArray[] = $field;
					}
					if( preg_match('/(email|contributorName|fullName)/', implode(' ', $warningArray) ) ){
						$this->incompleteRecords++;
						if(!empty($user)) $incompleteRecordsDups++;
						return WP_CLI::warning("***PROFILE DATA: (starting at char #{$this->startBracketLocation}) User " . $jsonItem['contributorName'] . ' does not have the following data: ' .implode(', ', $warningArray) . '***');
					}
					if( !empty($warningArray) ) WP_CLI::warning("PROFILE DATA: (starting at char #{$this->startBracketLocation}) User " . $jsonItem['contributorName'] . ' does not have the following data: ' .implode(', ', $warningArray));
					

					//BUILD DATA ARRAY
					foreach( $jsonItem as $fieldKey=>$fieldValue ){
						if( in_array($fieldKey, $this->fieldDataModel) ){
							//build dataArray
							if( !empty($fieldValue) && is_array($fieldValue) ){
								if( $fieldKey === 'users' ){
									foreach( $fieldValue as $subFieldKey=>$subFieldValue ){
										$dbKey = array_search($subFieldKey, $this->fieldDataModel['profileQuestions']);
										if( $dbKey ){
											$dataArray['profile_data'][$dbKey] = $subFieldValue;
										}
									}
								}
							}//is_array($fieldValue)
							else $dataArray[$fieldKey] = $fieldValue;
						}//in_array($fieldKey, $this->fieldDataModel)
					}//foreach $jsonItem


					//ADD DATA TO DATABASE
					if( empty($user) ){
						//ADD USER
						$dataAdded = $this->addUser( $dataArray );

						//output feedback
						if( $dataAdded ){
							WP_CLI::success("{$timeStamp}: Record added! (starting at char #{$this->startBracketLocation})");
							$this->stillBroken = false;
							//add member
							$this->numberRecords++;
							$this->numRecordsAdded++;
						}
						else WP_CLI::warning("PROFILE DATA: upload failed. Something went wrong with the upload (starting at char #{$this->startBracketLocation}).");

						//ADD PROFILE DATA
						if( !empty($dataArray['profile_data']) ){
							foreach( $dataArray['profile_data'] as $field=>$value ){
								$profileDataAdded = $this->addProfileData( $field, $value );
							}
						}
					}//empty($user)
					else{
						//UPDATE RECORD?
						WP_CLI::warning("PROFILE DATA: {$timeStamp}: (starting at char #{$this->startBracketLocation}) request to update user {$jsonItem['contributorName']}");
					}//!empty($user)
				}//$action = user?
				//ADD COMMENTS
				elseif( $action === 'comments' ){
					if( !empty($jsonItem['comments']) ){
						foreach( $jsonItem['comments'] as $comment ){
							$this->addComment( $comment, $user->wp_id );
						}
					}
					else WP_CLI::line("User {$jsonItem['contributorName']} does not have any comments, moving on...");
				}
				else WP_CLI::warning("Unrecognized action ('{$action}')");
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
					$this->parseJsonString( $updatedJson, $i, $action, $tryNum);
				}
				else{
					$this->badRecords++;
					$this->isGoodJson = true;
					$this->run = false;
					//add member
					$this->numberRecords++;
					
					//BAD JSON :-(
					$this->badJSON = $updatedJson;
					WP_CLI::warning("JSON ERROR: bad JSON (starting at char #{$this->startBracketLocation})\n");
				}
			}
			return true;
		}//parseJsonString()



		//ADD USER
		private function addUser($data){
			global $wpdb;
			$feedback = 'USER: ';
			$recordsAdded = false;
			$user = false;
			//possibly change this to look for existance of ning username in usermeta table instead
			//$record = $wpdb->get_row( "SELECT ID, user_email FROM {$wpdb->base_prefix}users WHERE user_email='{$data['email']}'" );
			$record = $wpdb->get_row( "SELECT wp_id, ning_id FROM {$wpdb->base_prefix}ning_user_lookup WHERE ning_id='{$jsonItem['contributorName']}'" );
			if( $record ){
				$this->user = $record->wp_id;
				WP_CLI::warning( 'USER: ' . $record->ning_id . '" already exists.' );
			}
			else{
				//CREATE USER
				$wpdb->insert(
					$wpdb->base_prefix.'users', 
					array(
						'user_login' => $data['contributorName'],
						'user_nicename' => $data['fullName'],
						'user_pass' => md5( $this->generatePassword(20) ),
						'user_email' => $data['email'],
						'user_registered' => $data['createdDate'],
						'user_activation_key' => md5( $this->generatePassword(20) ),
					), 
					array('%s', '%s', '%s', '%s', '%s')
				);
				$user = $this->user = $wpdb->insert_id;
				if( $this->user ){
					$recordsAdded = true;
					//ADD PROFILE DATA HERE!!!!
					$this->addProfileData( '1', $data['fullName'] );
				}
				$feedback .= $data['contributorName'] . ($wpdb->insert_id ? " " : " NOT ") . "added to user table";

				//ADD USER TO MEMBER LOOK UP TABLE
				$wpdb->insert(
					$wpdb->base_prefix.'ning_user_lookup', 
					array(
						'wp_id' => $wpdb->insert_id,
						'ning_id' => $data['contributorName']
					), 
					array('%s', '%s')
				);
				if( $this->user ) $recordsAdded = true;
				$feedback .= " and was" . ($wpdb->insert_id ? " " : " NOT ") . "added to the ning lookup table";

				if( $recordsAdded ) WP_CLI::success($feedback);
				else WP_CLI::warning($feedback);

				return $user;
			}//else ($record)
		}//addUser()



		//ADD COMMENT
		private function addComment($comm, $userUid){
			global $wpdb;
			$dbPrefix = $wpdb->base_prefix;
			$url = get_bloginfo( 'url' );
			$timeStamp = date( 'Y-m-d H:i:s', time() );
		
			if( !empty($comm) && !empty($userUid) ){
				$commentUser = $wpdb->get_row( "SELECT wp_id, ning_id FROM {$dbPrefix}ning_user_lookup WHERE ning_id='{$comm['contributorName']}'" );
				if( !empty($commentUser) ){
					$commentUid = $commentUser->wp_id;
					$existingComment = $wpdb->get_row( "SELECT id FROM {$dbPrefix}bp_activity WHERE user_id='{$commentUid}' AND secondary_item_id='".str_replace( ':Comment:', '-', $comm['id'] )."'" );
					if( empty($existingComment) ){
						$commentAdded = $wpdb->insert(
							$wpdb->base_prefix.'bp_activity', 
							array(
								'user_id' => $commentUid,
								'component' => 'ning_comment',// <-- THIS NEEDS TO BE CHANGED!!!!
								'type' => 'activity_update',
								'action' => "<a href='{$url}/members/{$commentUid}'>{COMMENTER}</a> commented on <a href='{$url}/members/{$userUid}'>{COMMENTED}</a> profile.",
								'primary_link' => "{$url}/members/{$userUid}",
								'content' => $comm['description'],
								'date_recorded' => $comm['createdDate'],
								'secondary_item_id' => str_replace( ':Comment:', '-', $comm['id'] )
							), 
							array('%d', '%s', '%s', '%d', '%s', '%s', '%s')
						);
						//output feedback
						if( $commentAdded ){
							$this->numComments++;
							//if( $this->numComments === 10 ) $this->run = false;
							WP_CLI::success("COMMENT: ({$timeStamp}) Comment added for user {$userUid} by user {$commentUid}");
						}
						else WP_CLI::warning("COMMENT: upload failed for {$userUid} by user {$commentUid} (starting at char #{$this->startBracketLocation}).");
					}
					else WP_CLI::warning("COMMENT: comment already exists for {$userUid} by user {$commentUid} (starting at char #{$this->startBracketLocation}).");
				}//!empty($commentUser)
				else WP_CLI::warning('COMMENT: Upload failed. No user with a Ning ID of ' . $comm['contributorName'] . ' was found.');
			}//!empty($comm) && !empty($userUid)
		}//addComment()



		//ADD PROFILE DATA
		private function addProfileData( $field=null, $value='' ){
			global $wpdb;
			$dbPrefix = $wpdb->base_prefix;
			$uid = $this->user;

			if( empty($field) ) WP_CLI::warning('PROFILE DATA: field not provided, no entry added.');
			else{
				//see if record already exists for user
				$record = $wpdb->get_row( "SELECT id FROM {$dbPrefix}bp_xprofile_data WHERE user_id='{$uid}' AND field_id='{$field}'" );
				if( $record ) return WP_CLI::line('PROFILE DATA: a record already exists for this user.');
				else{
					//add profile data
					$dataAdded = $wpdb->insert(
						$dbPrefix.'bp_xprofile_data',
						array(
							'field_id' => $field,
							'user_id' => $uid,
							'value' => $value, 
							'last_updated' => date( 'Y-m-d H:i:s', time() )
						), 
						array('%d', '%d', '%s')
					);
				}

				//output feedback
				if( $dataAdded ) WP_CLI::success("PROFILE DATA: User ID {$uid} '{$this->fieldDataModel['profileQuestions'][$field]}' added");
				else WP_CLI::warning("PROFILE DATA: Attempt to add '{$field}' for user ID {$uid}");
			}
		}//addProfileData()



		public function migrate( $iArr, $aArr ){
			//CHECK THAT JSON FILE HAS BEEN PROVIDED - if not throw an error
			if( !empty($iArr[0]) && !file_exists(dirname(__FILE__)."/../json/{$iArr[0]}" ) ) WP_CLI::error( "Migration Failed - JSON file not found" );

			global $wpdb;
			$dbPrefix = $wpdb->base_prefix;
			$json = file_get_contents(dirname(__FILE__)."/../json/{$iArr[0]}");
//echo preg_match_all('/"level":/', $json);
//return;
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
			$action = $aArr['action'];
			//$numRecordsToRecord = 10;
			//$numCharsToRecord = 5022;

			//LOOP THROUGH JSON FILE
			for($i=$startChar; $i<$stopChar; $i++){
				if( !empty($numRecordsToRecord) && $numRecordsToRecord === $this->numMembers) break;

				//this is ne user's json
				if( $numBrackets === 0 && ($json[$i] == ',' $i===$stopChar) ){
					$this->parseJsonString( $this->memberArray[$this->numMembers], $i, $action );
					$this->numMembers++;
					//stop loop when a json error is hit
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
			echo "\n\n\n==========================\n::MIGRATION SUMMARY::\n==========================\n";
			echo "{$stopChar} characters read.\n";
			echo "There were {$this->numMembers} TOTAL user records!\n";
			echo "There were {$this->numRecordsAdded} USERS added!\n";
			echo "There were {$this->numComments} COMMENTS added!\n";
			echo "There were {$this->incompleteRecords} INCOMPLETE records {$this->incompleteRecordsDups} of them are duplicate users!\n";
			echo "There were {$this->badRecords} BAD user records!\n";
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
		}//migrate()




		/**
		 * OUTPUT SYNTAX FOR COMMAND
		 */
		public static function help() {
			WP_CLI::line( "usage: wp gvlm migrate [--action=comments|users] [path/to/json/file]" );
		}
	}
