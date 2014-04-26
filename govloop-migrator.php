<?php
	/*
		Plugin Name: GovLoop Migration Tool
	*/
	if( defined('WP_CLI') && WP_CLI ){
		include( dirname(__FILE__) . '/GVLM_Govloop_Migrator.php' );
		include( dirname(__FILE__) . '/GVLM_Govloop_To_SQL_Migrator.php' );
	}
