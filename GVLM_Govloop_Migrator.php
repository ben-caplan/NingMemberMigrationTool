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
	WP_CLI::add_command( 'gvlm', 'GVLM_Govloop_Migrator' );




	/**
	 * GVLM CLASS
	 */
	class GVLM_Govloop_Migrator extends WP_CLI_Command {
		//LOCAL VARS
		private $user = null, 
				$fieldDataModel = array(
					"fullName",
					"gender",
					"location",
					"country",
					"zip",
					"birthdate",
					"profileQuestions" => array(
						"Type of Government",
						"Current Title:",
						"Current Agency or Organization",
						"What Best Describes Your Role?",
						"Educational Background (Degree, School):",
						"Profile Links - Blog, Twitter, LinkedIn, Facebook",
						"Topics I Care About",
						"Are you involved (buy, recommend, influence) in the purchase of:",
						"How Did You Hear About GovLoop?",
						"I work for the government because:",
						"My favorite public servant is:"
					),
					"profilePhoto"
				);

		//GENERAT PASSWORD
		private function generatePassword( $length ){
			$chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_-=+;:,.?";
			$password = substr( str_shuffle( $chars ), 0, $length );
			return $password;
		}//generatePassword()


		//ADD USER
		private function addUser($data){
			global $wpdb;
			//possibly change this to look for existance of ning username in usermeta table instead
			$record = $wpdb->get_row( "SELECT ID, user_email FROM {$wpdb->base_prefix}users WHERE user_email='{$data['email']}'" );

			if( $record ){
				$this->user = $record->ID;
				WP_CLI::warning( 'USER: a user with the email "' . $record->user_email . '" already exists.' );
			}
			else{
				//CREATE USER
				$wpdb->insert(
					$wpdb->base_prefix.'users', 
					array(
						'user_login' => $data['email'],
						'user_pass' => md5( $this->generatePassword(20) ),
						'user_email' => $data['email'],
						'user_registered' => $data['createdDate'],
						'user_activation_key' => md5( $this->generatePassword(20) ),
					), 
					array('%s', '%s', '%s', '%s', '%s')
				);
				$this->user = $wpdb->insert_id;
				//ADD USER TO MEMBER LOOK UP TABLE
				

				//output feedback
				if( $wpdb->insert_id ) WP_CLI::success( 'User ' . $data['fullName'] . ' entered!' );
				else WP_CLI::warning('USER: ' . $data['fullName'] . ' NOT entered, something went wrong.');

				//STORE NING 'contributorName'
				$userMeta = $wpdb->insert(
					$wpdb->base_prefix.'usermeta', 
					array(
						'user_id' => $this->user,
						'meta_key' => 'ning_contributorName',
						'meta_value' => $data['contributorName']
					), 
					array('%d', '%s', '%s')
				);
				//output feedback
				if( $userMeta ) WP_CLI::success( 'User ' . $data['fullName'] . '\'s contributorName stored!' );
				else WP_CLI::warning('USER: ' . $data['fullName'] . '\'s contributorName NOT entered, something went wrong.');
			}
		}//addUser()


		//ADD COMMENT
		private function addComment($comm){
			global $wpdb;
			$dbPrefix = $wpdb->base_prefix;
		
			if( !empty($comm) ){
				$uidData = $wpdb->get_row( "SELECT umeta_id, user_id FROM {$dbPrefix}usermeta WHERE meta_key='ning_contributorName' AND meta_value='{$comm['contributorName']}'" );
				if( !empty($uid) ){
					$uid = $uidData->user_id;
					$commentAdded = $wpdb->insert(
						$wpdb->base_prefix.'bp_activity', 
						array(
							'user_id' => $uid,
							'component' => 'activity',
							'type' => 'activity_update',
							'action' => '',
							'content' => $comm['description'],
							'date_recorded' => $comm['createdDate']
						), 
						array('%d', '%s', '%s', '%d', '%s', '%s')
					);
					//output feedback
					if( $commentAdded ) WP_CLI::success('Comment added!');
					else WP_CLI::warning('COMMENT: upload failed. Something went wrong with the upload.');
				}
				else WP_CLI::warning('COMMENT: upload failed. No user with a Ning ID of ' . $comm['contributorName'] . ' was found.');
			}
		}//addComment()


		//ADD PROFILE DATA
		private function addProfileData( $field=null, $value='' ){
			global $wpdb;
			$dbPrefix = $wpdb->base_prefix;
			$uid = $this->user;

			if( empty($field) ) WP_CLI::warning('PROFILE DATA: profile field with ID of "' .$field. '" not found. This type does not yet exist, so no record has been added.');
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
				if( $dataAdded ) WP_CLI::success("Field data added for user ID {$uid}");
				else WP_CLI::warning('PROFILE DATA: upload failed. Something went wrong with the upload.');
			}
		}//addProfileData()


		function migrate( $iArr, $aArr ){
			//CHECK THAT JSON FILE HAS BEEN PROVIDED - if not throw an error
			if( empty($aArr['action']) ) WP_CLI::error( "Migration Failed - Must specify an action (user or comments)" );
			if( !empty($iArr[0]) && !file_exists(dirname(__FILE__)."/json/{$iArr[0]}" ) ) WP_CLI::error( "Migration Failed - JSON file not found" );


			//LOCAL VARS
			global $wpdb;
			$fieldDataModelIndexedByID = array();
			$json = json_decode( file_get_contents(dirname(__FILE__)."/json/{$iArr[0]}"), true );


			//SET $fieldDataModelIndexedByID
			if( $aArr['action'] === 'users' ){
				foreach($this->fieldDataModel as $field=>$fieldValue){
					if( is_array($fieldValue) ){
						$fieldDataModelIndexedByID[$field] = array();
						foreach( $fieldValue as $subField=>$subFieldValue ){
							$fieldData = $wpdb->get_row("SELECT * FROM {$wpdb->base_prefix}bp_xprofile_fields WHERE name='{$subFieldValue}'");		
							if( !empty($fieldData) ) $fieldDataModelIndexedByID[$field][$fieldData->id] = $subFieldValue;
						}
					}
					$fieldData = $wpdb->get_row("SELECT * FROM {$wpdb->base_prefix}bp_xprofile_fields WHERE name='{$fieldValue}'");
					if( !empty($fieldData) ) $fieldDataModelIndexedByID[$fieldData->id] = $fieldValue;
				}
			}


			//LOOP THROUGH JSON
			foreach( $json as $item ){
				//ADD USER
				if( $aArr['action'] === 'users' ){
					$this->addUser( $item );
					foreach($item as $itemToAddKey=>$itemToAddValue){
						if( is_array($itemToAddValue) && !empty($fieldDataModelIndexedByID[$itemToAddKey]) && $fieldDataModelIndexedByID[$itemToAddKey] !== 'comments' ){
							foreach( $itemToAddValue as $subItemToAddKey=>$subItemToAddValue ){
								//get index and addProfileData
								$itemID = array_search($subItemToAddKey, $fieldDataModelIndexedByID[$itemToAddKey]);
								if( $itemID ) $this->addProfileData( $itemID, $subItemToAddValue );
							}//foreach $itemToAddValue
						}//is_array($itemToAddValue)
						else{
							//get index and addProfileData
							$itemID = array_search($itemToAddKey, $fieldDataModelIndexedByID);
							if( $itemID ) $this->addProfileData( $itemID, $itemToAddValue );
						}//!is_array($itemToAddKey)
					}//foreach $item
				}// if action === 'users'


				//COMMENTS
				if( $aArr['action'] === 'comments' && !empty($item['comments']) ){
					foreach( $item['comments'] as $comment ){
						$this->addComment( $comment );
					}
				}
			}
		}//migrate()




		/**
		 * OUTPUT SYNTAX FOR COMMAND
		 */
		public static function help() {
			WP_CLI::line( "usage: wp gvlm migrate [--action=comments|users] [path/to/json/file]" );
		}
	}
