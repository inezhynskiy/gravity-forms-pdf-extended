<?php

/*
Plugin Name: Gravity Forms PDF Extended
Plugin URI: http://www.gravityformspdfextended.com
Description: Gravity Forms PDF Extended allows you to save/view/download a PDF from the front- and back-end, and automate PDF creation on form submission. Our Business Plus package also allows you to overlay field onto an existing PDF.
Version: 3.5.3
Author: Blue Liquid Designs
Author URI: http://www.blueliquiddesigns.com.au

------------------------------------------------------------------------

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
*/

/*
 * As PDFs can't be generated if notices are displaying, turn off error reporting to the screen if not in debug mode.
 * Production servers should already have this done.
 */
 if(WP_DEBUG !== true)
 {
 	error_reporting(0);
 }
 
/*
 * Define our constants 
 */
define('PDF_EXTENDED_VERSION', '3.5.3'); 
define('GF_PDF_EXTENDED_SUPPORTED_VERSION', '1.7'); 
define('GF_PDF_EXTENDED_WP_SUPPORTED_VERSION', '3.5'); 
define('GF_PDF_EXTENDED_PHP_SUPPORTED_VERSION', '5'); 
  
define('PDF_PLUGIN_DIR', plugin_dir_path( __FILE__ ));  
define('PDF_PLUGIN_URL', plugin_dir_url( __FILE__ )); 
define("PDF_SETTINGS_URL", site_url() .'/wp-admin/admin.php?page=gf_settings&subview=PDF'); 
define('PDF_SAVE_FOLDER', 'PDF_EXTENDED_TEMPLATES'); 
define('PDF_SAVE_LOCATION', get_stylesheet_directory().'/'.PDF_SAVE_FOLDER.'/output/'); 
define('PDF_FONT_LOCATION', get_stylesheet_directory().'/'.PDF_SAVE_FOLDER.'/fonts/'); 
define('PDF_TEMPLATE_LOCATION', get_stylesheet_directory().'/'.PDF_SAVE_FOLDER.'/'); 
define('PDF_TEMPLATE_URL_LOCATION', get_stylesheet_directory_uri().'/'. PDF_SAVE_FOLDER .'/'); 
define('GF_PDF_EXTENDED_PLUGIN_BASENAME', plugin_basename(__FILE__)); 

/* 
 * Include the core helper files
 */
 include PDF_PLUGIN_DIR . 'helper/api.php';
 include PDF_PLUGIN_DIR . 'helper/data.php'; 
 include PDF_PLUGIN_DIR . 'helper/notices.php'; 
 include PDF_PLUGIN_DIR . 'helper/pdf-configuration-indexer.php'; 	
 include PDF_PLUGIN_DIR . 'helper/installation-update-manager.php'; 				
 
 /*
  * Initialise our data helper class
  */
 global $gfpdfe_data;
 $gfpdfe_data = new GFPDFE_DATA();    
 
 include PDF_PLUGIN_DIR . 'pdf-settings.php';
 include PDF_PLUGIN_DIR . 'helper/pdf-common.php';

 /* 
  * Initiate the class after Gravity Forms has been loaded using the init hook.
  */
   add_action('init', array('GFPDF_Core', 'pdf_init'));
   add_action('wp_ajax_support_request', array('GFPDF_Settings_Model', 'gfpdf_support_request'));
   

class GFPDF_Core extends PDFGenerator
{
	public $render;
	static $model;
		
	/*
	 * Main Controller 
	 * First function fired when plugin is loaded
	 * Determines if the plugin can run or not
	 */
	public static function pdf_init() 
	{
		global $gfpdfe_data;
   
		/*
		 * Set the notice type 
		 */
		self::set_notice_type();
   
	   /*
	    * Add localisation support
	    */ 
	    load_plugin_textdomain(GF_PDF_EXTENDED_PLUGIN_BASENAME, false, PDF_PLUGIN_DIR . 'resources/languages/' );

		/*
		 * Call our Settings class which will do our compatibility processing
		 */
		$gfpdfe_data->settingsClass = new GFPDF_Settings();		
		 
		/*
		 * We'll initialise our model which will do any function checks ect
		 */
		 include PDF_PLUGIN_DIR . 'model/pdf.php';			 
		 self::$model = new GFPDF_Core_Model();					 
		 			 	
		/*
		* Check for any major compatibility issues early
		*/
		if(self::$model->check_major_compatibility() === false)
		{
			/*
			 * Major compatibility errors (WP version, Gravity Forms or PHP errors)
			 * Exit to prevent conflicts
			 */
			return;  
		}
		
		/*
		* Some functions are required to monitor changes in the admin area
		* and ensure the plugin functions smoothly
		*/
		add_action('admin_init', array('GFPDF_Core', 'fully_loaded_admin'), 9999); /* run later than usual to give our auto initialiser a chance to fire */
		add_action('after_switch_theme', array('GFPDF_InstallUpdater', 'gf_pdf_on_switch_theme'), 10, 2); /* listen for a theme chance and sync our PDF_EXTENDED_TEMPLATE folder */				 		 		
		
		/*
		 * Only load the plugin if the following requirements are met:
		 *  - Load on Gravity Forms Admin pages
		 *  - Load if requesting PDF file
		 *  - Load if on Gravity Form page on the front end
		 *  - Load if receiving Paypal IPN
		 */		 		
		 if( ( is_admin() && isset($_GET['page']) && (substr($_GET['page'], 0, 3) === 'gf_') ) ||
		 	  ( isset($_GET['gf_pdf']) ) ||
			  ( RGForms::get("page") == "gf_paypal_ipn") ||
			  ( isset($_POST["gform_submit"]) && GFPDF_Core_Model::valid_gravity_forms() || 
			  	(  defined( 'DOING_AJAX' ) && DOING_AJAX && isset($_POST['action']) && isset($_POST['gf_resend_notifications'])) )
			)
		 {			
			/*
			 * Initialise the core class which will load the __construct() function
			 */
			global $gfpdf;
			$gfpdf = new GFPDF_Core();  		 	
		 }
		 
		 return;
				  
   }	
	
	public function __construct()
	{
		global $gfpdfe_data;	

	    /* 
		 * Include the core files
		 */ 
		 include PDF_PLUGIN_DIR . 'helper/pdf-render.php'; 
		 include PDF_PLUGIN_DIR . 'helper/pdf-entry-detail.php';  
		
		/*
		* Set up the PDF configuration and indexer
		* Accessed through $this->configuration and $this->index.
		*/
		parent::__construct();				

		/*
		* Run our scripts and add the settings page to the admin area 
		*/				
		add_action('admin_init',  array($this, 'gfe_admin_init'), 9);																				
				
		/*
		 * Ensure the system is fully installed		 
		 * We run this after the 'settings' page has been set up (above)		 
		 */
		if(GFPDF_Core_Model::is_fully_installed() === false)
		{
			return; 
		}	
		
		/*
		* Add our main hooks
		*/		
		add_action('gform_entries_first_column_actions', array('GFPDF_Core_Model', 'pdf_link'), 10, 4);
		add_action("gform_entry_info", array('GFPDF_Core_Model', 'detail_pdf_link'), 10, 2);
		add_action('wp', array('GFPDF_Core_Model', 'process_exterior_pages'));		
		

		/*
		* Apply default filters
		*/  
		add_filter('gfpdfe_pdf_template', array('PDF_Common', 'do_mergetags'), 10, 3); /* convert mergetags in PDF template automatically */
		add_filter('gfpdfe_pdf_template', 'do_shortcode', 10, 1); /* convert shortcodes in PDF template automatically */ 		

		/* Check if on the entries page and output javascript */
		if(is_admin() && rgget('page') == 'gf_entries')
		{
			wp_enqueue_script( 'gfpdfeentries', PDF_PLUGIN_URL . 'resources/javascript/entries-admin.min.js', array('jquery') );		
		}		
		
		/*
		* Register render class
		*/		
		$this->render = new PDFRender();
		
		/*
		* Run the notifications filter / save action hook if the web server can write to the output folder
		*/
		if($gfpdfe_data->can_write_output_dir === true)
		{
			add_action('gform_after_submission', array('GFPDF_Core_Model', 'gfpdfe_save_pdf'), 10, 2);
			add_filter('gform_notification', array('GFPDF_Core_Model', 'gfpdfe_create_and_attach_pdf'), 100, 3);  /* ensure it's called later than standard so the attachment array isn't overridden */	  		  
		}
		
	}
	
	/*
	 * Do processes that require Wordpress Admin to be fully loaded
	 */
	 public static function fully_loaded_admin()
	 {

	 	global $gfpdfe_data;
	
	 	/*
	 	 * Don't run initialiser if we cannot...
	 	 */
		if($gfpdfe_data->allow_initilisation === false)
		{		 	
			/*
			 * Prompt user about a server configuration problem
			 */
			add_action($gfpdfe_data->notice_type, array("GFPDF_Notices", "gf_pdf_server_problem_detected"));		
			return false; 
		}	 	

		/*
		 * Check if we have direct write access to the server 
		 */
		GFPDF_InstallUpdater::check_filesystem_api();

		/*
		 * Check if we can automatically deploy the software. 
		 * 90% of sites should be able to do this as they will have 'direct' write abilities 
		 * to their server files.
		 */
		GFPDF_InstallUpdater::maybe_deploy();	

		/*
		 * Check if we need to deploy the software
		 */
		 self::check_deployment();

		 /*
		  * Check if the user has switched themes and they haven't yet prompt user to copy over directory structure
		  * If the plugin has just initialised we won't check for a theme swap as initialisation will reset this value
		  */ 
		  if(!rgpost('upgrade'))
		  {
		  	GFPDF_InstallUpdater::check_theme_switch();		 
		  }
	 }
	 
	 /*
	  * Depending on what page we are on, we need to fire different notices 
	  * We've added our own custom notice to the settings page as some functions fire later than the normal 'admin_notices' action 	  
	  */
	 private static function set_notice_type()
	 {
	 	global $gfpdfe_data;

	 	if(PDF_Common::is_settings())
	 	{
	 		$gfpdfe_data->notice_type = 'gfpdfe_notices';
	 	}
	 	else if (is_multisite() && is_network_admin())
	 	{
	 		$gfpdfe_data->notice_type = 'network_admin_notices';
	 	}
	 	else
	 	{
	 		$gfpdfe_data->notice_type = 'admin_notices';
	 	}
	 }

	 /*
	  * Check if the software needs to be deployed/redeployed
	  */
	  public static function check_deployment()
	  {

	  		global $gfpdfe_data;

	  		/*
	  		 * Check if client is using the automated installer 
	  		 * If installer has issues or client cannot use auto installer (using FTP/SSH ect) then run the usual 
	  		 * initialisation messages. 
	  		 */
	  		if($gfpdfe_data->automated === true && $gfpdfe_data->fresh_install === true & get_option('gfpdfe_automated_install') != 'installing')
	  		{
	  			return;
	  		}
			
			/*
			 * Check if GF PDF Extended is correctly installed. If not we'll run the installer.
			 */	
			$theme_switch = get_option('gfpdfe_switch_theme'); 

			if( (
					(get_option('gf_pdf_extended_installed') != 'installed')
				) && (!rgpost('upgrade') )
				  && (empty($theme_switch['old']) )
			  )
			{
				/*
				 * Prompt user to initialise plugin
				 */
				 add_action($gfpdfe_data->notice_type, array("GFPDF_Notices", "gf_pdf_not_deployed_fresh")); 	
			}
			elseif( (
						( !is_dir(PDF_TEMPLATE_LOCATION))  ||
						( !file_exists(PDF_TEMPLATE_LOCATION . 'configuration.php') ) ||
						( !is_dir(PDF_SAVE_LOCATION) )  						
					)
					&& (!rgpost('upgrade'))
					&& (empty($theme_switch['old']) )

				  )
			{

				/*
				 * Prompt user that a problem was detected and they need to redeploy
				 */
				add_action($gfpdfe_data->notice_type, array("GFPDF_Notices", "gf_pdf_problem_detected"));
			}	  
	  }
	
	/**
	 * Add our scripts and settings page to the admin area 
	 */
	function gfe_admin_init()
	{					
									
		/* 
		 * Configure the settings page
		 */
		  wp_enqueue_style( 'pdfextended-admin-styles', PDF_PLUGIN_URL . 'resources/css/admin-styles.min.css', array('dashicons'), '1.1' );		
		  wp_enqueue_script( 'pdfextended-settings-script', PDF_PLUGIN_URL . 'resources/javascript/admin.min.js' );	
		 
		 /*
		  * Register our scripts/styles with Gravity Forms to prevent them being removed in no conflict mode
		  */
		  add_filter('gform_noconflict_scripts', array('GFPDF_Core', 'register_gravityform_scripts')); 
		  add_filter('gform_noconflict_styles', array('GFPDF_Core', 'register_gravityform_styles')); 

		  add_filter('gform_tooltips', array('GFPDF_Notices', 'add_tooltips'));	 	  
		 
    	 GFPDF_Settings::settings_page();	
		  
	}
	
	/*
	 * Register our scripts with Gravity Forms so they aren't removed when no conflict mode is active
	 */
	public static function register_gravityform_scripts($scripts)
	{
		$scripts[] = 'pdfextended-settings-script';
		$scripts[] = 'gfpdfeentries';
		
		return $scripts;
	}

	/*
	 * Register our styles with Gravity Forms so they aren't removed when no conflict mode is active
	 */	
	public static function register_gravityform_styles($styles)
	{
		$styles[] = 'pdfextended-admin-styles';					
		
		return $styles;
	}	
	
}

/*
 * array_replace_recursive was added in PHP5.3
 * Add fallback support for those with a version lower than this
 * and Wordpress still supports PHP5.0 to PHP5.2
 */
if (!function_exists('array_replace_recursive'))
{
	function array_replace_recursive()
	{
	    // Get array arguments
	    $arrays = func_get_args();

	    // Define the original array
	    $original = array_shift($arrays);

	    // Loop through arrays
	    foreach ($arrays as $array)
	    {
	        // Loop through array key/value pairs
	        foreach ($array as $key => $value)
	        {
	            // Value is an array
	            if (is_array($value))
	            {
	                // Traverse the array; replace or add result to original array
	                $original[$key] = array_replace_recursive($original[$key], $array[$key]);
	            }

	            // Value is not an array
	            else
	            {
	                // Replace or add current value to original array
	                $original[$key] = $value;
	            }
	        }
	    }

	    // Return the joined array
	    return $original;
	} 
}
