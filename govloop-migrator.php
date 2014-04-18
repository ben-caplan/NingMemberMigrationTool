<?php
	/*
		Plugin Name: GovLoop Migration Tool
	*/
	if( defined('WP_CLI') && WP_CLI )
		include( dirname(__FILE__) . '/GVLM_Govloop_Migrator.php' );
