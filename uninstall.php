<?php

// This script is called when plugin is unistalled.

if( !defined( 'ABSPATH') &&  !defined('WP_UNINSTALL_PLUGIN') )
    exit();

// delete plugin specific options 
delete_option(CONTENT_4_PARTNERS_SETTINGS);