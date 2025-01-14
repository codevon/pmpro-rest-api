<?php
/*
Plugin Name: REST API Endpoints for Paid Memberships Pro
Plugin URI: https://eighty20results.com/wordpress-plugins/pmpro-rest-api/
Description: Adds REST API endpoints for Paid Memberships Pro
Version: 1.5
Author: eighty20results
Author URI: https://eighty20results.com/thomas-sjolshagen/
Text Domain: pmpro-rest-api
Domain Path: /languages
License: GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/
/*

	Copyright 2016-2017 Eighty / 20 Results by Wicked Strong Chicks, LLC (thomas@eighty20results.com)

	REST API Endpoints for Paid Memberships Pro is free software; you can redistribute
	it and/or modify it under the terms of the GNU General Public License,
	version 2, as published by the Free Software Foundation.

	REST API Endpoints for Paid Memberships Pro is distributed in the hope that
	it will be useful, but WITHOUT ANY WARRANTY; without even the
	implied warranty of MERCHANTABILITY or FITNESS FOR A
	PARTICULAR PURPOSE.  See the GNU General Public License for more
	details.

	You should have received a copy of the GNU General Public License
	along with REST API Endpoints for Paid Memberships Pro; if not, see
	https://www.gnu.org/licenses/gpl-2.0.html
*/

defined( 'ABSPATH' ) || die( 'Cannot access plugin sources directly' );
define( 'E20R_PMPRORESTAPI_VER', '1.5' );

if ( ! class_exists( '\pmproRestAPI' ) ) {
	
	
	class pmproRestAPI extends WP_REST_Controller {
		
		/**
		 * @var pmproRestAPI $instance The class instance
		 */
		static $instance = null;
		
		/**
		 * @var string $option_name The name to use in the WordPress options table
		 */
		private $option_name;
		
		/**
		 * @var array $options Array of levels with setup fee values.
		 */
		private $options;
		
		/**
		 * @var bool $loged_in
		 */
		private $logged_in;
		
		/**
		 * @var WP_User $user WP_User object for the (logged in) user.
		 */
		private $user;
		
		/**
		 * pmproRestAPI constructor.
		 */
		public function __construct() {
			
			$this->option_name = strtolower( get_class( $this ) );
			
			add_action( 'plugins_loaded', array( $this, 'addRoutes' ), 10 );
			add_action( 'http_api_curl', array( $this, 'forceTLS12' ), 10 );
			add_action( 'init', array( $this, 'fixSlugRequestForPMPro' ), 10 );
			
			/* Commented out for now
			add_action( 'init', array( $this, 'addRESTSupport' ), 25 );
			add_filter( "rest_prepare_post", array( $this, 'checkAccessForRequest'), 10, 3 );
			add_filter( "rest_prepare_page", array( $this, 'checkAccessForRequest'), 10, 3 );
			*/
		}
		
		public function fixSlugRequestForPMPro() {
			
			if ( ! function_exists( 'pmpro_getOption' ) ) {
				return;
			}
			
			$filterqueries = pmpro_getOption( "filterqueries" );
			
			if ( ! empty( $filterqueries ) && ( defined( 'PMPRO_VERSION' ) && version_compare(PMPRO_VERSION, '1.9.2', '<' ) ) ) {
				if (WP_DEBUG) {
					error_log("Loading custom filter for PMPro search - REST API Compabitility fix");
				}
				remove_filter( 'pre_get_posts', 'pmpro_search_filter', 10 );
				add_filter( 'pre_get_posts', array( $this, 'pmproSearchFilter' ), 10, 1 );
			}
		}
		
		public function pmproSearchFilter( $query ) {
			
			global $current_user;
			global $wpdb;
			global $pmpro_pages;
			
			if ( ! function_exists( 'pmpro_getMembershipLevelsForUser' ) ) {
				return $query;
			}
			
			//hide pmpro pages from search results
			if ( ! $query->is_admin && $query->is_search && empty( $query->query['post_parent'] ) ) {
				if ( empty( $query->query_vars['post_parent'] ) )    //avoiding post_parent queries for now
				{
					$query->set( 'post__not_in', $pmpro_pages );
				}
				
				$query->set( 'post__not_in', $pmpro_pages ); // id of page or post
			}
			
			//hide member pages from non-members (make sure they aren't hidden from members)
			if ( ! $query->is_admin &&
			     ! $query->is_singular &&
			     empty( $query->query['post_parent'] ) &&
			     (
				     empty( $query->query_vars['post_type'] ) ||
				     in_array( $query->query_vars['post_type'], apply_filters( 'pmpro_search_filter_post_types', array(
					     "page",
					     "post",
				     ) ) )
			     ) && (
			     ( ! defined( 'REST_REQUEST' ) || ( defined( 'REST_REQUEST' ) && false === REST_REQUEST ) )
			     )
			) {
				
				//get page ids that are in my levels
				if ( ! empty( $current_user->ID ) ) {
					$levels = pmpro_getMembershipLevelsForUser( $current_user->ID );
				} else {
					$levels = false;
				}
				$my_pages     = array();
				$member_pages = array();
				
				if ( $levels ) {
					foreach ( $levels as $key => $level ) {
						//get restricted posts for level
						
						// make sure the object contains membership info.
						if ( isset( $level->ID ) ) {
							
							$sql = $wpdb->prepare( "
						SELECT page_id
						FROM {$wpdb->pmpro_memberships_pages}
						WHERE membership_id = %d",
								$level->ID
							);
							
							$member_pages = $wpdb->get_col( $sql );
							$my_pages     = array_unique( array_merge( $my_pages, $member_pages ) );
						}
					} // foreach
				} // if($levels)
				
				//get hidden page ids
				if ( ! empty( $my_pages ) ) {
					$sql = "SELECT page_id FROM $wpdb->pmpro_memberships_pages WHERE page_id NOT IN(" . implode( ',', $my_pages ) . ")";
				} else {
					$sql = "SELECT page_id FROM $wpdb->pmpro_memberships_pages";
				}
				$hidden_page_ids = array_values( array_unique( $wpdb->get_col( $sql ) ) );
				
				if ( $hidden_page_ids ) {
					if ( empty( $query->query_vars['post_parent'] ) )            //avoiding post_parent queries for now
					{
						$query->set( 'post__not_in', $hidden_page_ids );
					}
				}
				
				//get categories that are filtered by level, but not my level
				global $pmpro_my_cats;
				$pmpro_my_cats = array();
				
				if ( $levels ) {
					foreach ( $levels as $key => $level ) {
						$member_cats   = pmpro_getMembershipCategories( $level->id );
						$pmpro_my_cats = array_unique( array_merge( $pmpro_my_cats, $member_cats ) );
					}
				}
				
				//get hidden cats
				if ( ! empty( $pmpro_my_cats ) ) {
					$sql = "SELECT category_id FROM $wpdb->pmpro_memberships_categories WHERE category_id NOT IN(" . implode( ',', $pmpro_my_cats ) . ")";
				} else {
					$sql = "SELECT category_id FROM $wpdb->pmpro_memberships_categories";
				}
				
				$hidden_cat_ids = array_values( array_unique( $wpdb->get_col( $sql ) ) );
				
				//make this work
				if ( $hidden_cat_ids ) {
					$query->set( 'category__not_in', $hidden_cat_ids );
					
					//filter so posts in this member's categories are allowed
					add_action( 'posts_where', 'pmpro_posts_where_unhide_cats' );
				}
			}
			
			return $query;
		}
		
		/**
		 * Add CPTs to the REST support for PMPro
		 */
		public function addRESTSupport() {
			
			global $wp_post_types;
			
			$supported_posts = apply_filters( 'pmpro_restapi_supported_post_types', array( 'post', 'page' ) );
			
			foreach ( $supported_posts as $type ) {
				
				if ( ! in_array( $type, array( 'post', 'page' ) ) && isset( $wp_post_types[ $type ] ) ) {
					$wp_post_types[ $type ]->show_in_rest = true;
				}
			}
			
		}
		
		/**
		 * Trigger if/when the REST API is preparing the post/page/CPT for inclusion. (Not working).
		 *
		 * @param WP_REST_Response $response
		 * @param WP_Post          $post
		 * @param WP_REST_Request  $request
		 *
		 * @return WP_REST_Response
		 */
		public function checkAccessForRequest( $response, $post, $request ) {
			
			if ( !function_exists( 'pmpro_has_membership_access' ) ) {
				return $response;
			}
			
			if ( false == pmpro_has_membership_access( $post->ID ) ) {
				$response = array( 'error' => esc_html__( "Access denied", "pmpro-rest-api" ) );
			}
			
			return $response;
		}
		
		/**
		 * Load all REST API endpoints for the PMPro add-on
		 */
		public function addRoutes() {
			
			// FIXME: Route definition for the levels array (if applicable)
			add_action( 'rest_api_init', function () {
				register_rest_route(
					'pmpro/v1',
					'hasaccess/(?P<post>\d+)/(?P<user>\d+)',
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'checkAccess' ),
						'permission_callback' => array( $this, 'hasRESTAccessPermission' ),
						'args'                => array(
							'post' => array(
								'validate_callback' => function ( $param, $request, $key ) {
									return is_numeric( $param );
								},
							),
							'user' => array(
								'validate_callback' => function ( $param, $request, $key ) {
									
									
									return ( empty( $param ) || is_numeric( $param ) );
								},
							),
						),
					)
				);
			} );
			
			add_action( 'rest_api_init', function () {
				register_rest_route(
					'pmpro/v1',
					'getlevelforuser/(?P<user>\d+)',
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'getLevelForUser' ),
						'permission_callback' => array( $this, 'hasRESTAccessPermission' ),
						'args'                => array(
							'user' => array(
								'validate_callback' => function ( $param, $request, $key ) {
									return is_numeric( $param );
								},
							),
						),
					)
				);
			} );
			
			add_action( 'rest_api_init', function () {
				register_rest_route(
					'pmpro/v1',
					'updatelevelforuser/(?P<user>\d+)/(?P<level>\d+)',
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'updateLevelForUser' ),
						'permission_callback' => array( $this, 'hasRESTAccessPermission' ),
						'args'                => array(
							'user' => array(
								'validate_callback' => function ( $param, $request, $key ) {
									return is_numeric( $param );
								},
							),
						),
					)
				);
			} );
		}
		
		
		/**
		 * Check access permission to the PMPro REST API endpoints
		 *
		 * @return bool|WP_Error
		 */
		public function hasRESTAccessPermission() {
			
			$required_capability = apply_filters( 'pmpro_restapi_access_role', 'manage_options' );
			
			if ( ! current_user_can( $required_capability ) ) {
				// return new WP_Error( 'rest_forbidden', esc_html__( "REST API Access: Permission denied", "pmpro-rest-ap" ) );
			}
			
			return true;
		}
		
		/**
		 * REST API endpoint handler for PMPro post/page access check
		 *
		 * @param WP_REST_Request $request
		 *
		 * @return array|bool|mixed|WP_Error
		 */
		public function checkAccess( $request ) {
			
			if ( ! function_exists('pmpro_has_membership_access') ) {
				return new WP_Error( 'rest_forbidden', esc_html__( 'Paid Memberships Pro plugin deactivated', 'pmpro-rest-api'));
			}
			
			$user_id = $request['user'];    //user id passed in
			
			$this->user = get_user_by( 'ID', $user_id );
			
			if ( empty( $this->user ) ) {
				return new WP_Error( 'rest_forbidden', esc_html__( 'Cannot validate access for unknown/invalid user', 'pmpro-rest-api' ) );
			}
			
			$this->logged_in = $this->user->exists();
			
			if ( false === $this->logged_in ) {
				
				return new WP_Error( 'rest_forbidden', esc_html__( 'REST API Access: User access denied', 'pmpro-rest-api' ) );
			}
			
			$post_id = $request['post'];    //post id to check
			
			return pmpro_has_membership_access( $post_id, $user_id, true );
		}
		
		/**
		 *
		 * Return the PMPro Membership Level for the specified user ID
		 *
		 * @param WP_REST_Request $request
		 *
		 * @return bool|WP_Error
		 */
		public function getLevelForUser( $request ) {
			
			if ( ! function_exists('pmpro_getMembershipLevelForUser') ) {
				return new WP_Error( 'rest_forbidden', esc_html__( 'Paid Memberships Pro plugin deactivated', 'pmpro-rest-api'));
			}
			
			$user_id = $request['user'];    //optional user id passed in
			
			$this->user = get_user_by( 'ID', $user_id );
			
			if ( empty( $this->user ) ) {
				
				return new WP_Error( 'rest_forbidden', esc_html__( 'Cannot check membership level for unknown/invalid user', 'pmpro-rest-api' ) );;
			}
			
			$this->logged_in = $this->user->exists();
			
			
			if ( false === $this->logged_in ) {
				
				return new WP_Error( 'rest_forbidden', esc_html__( 'User does not have access to the PMPro REST API', 'pmpro-rest-api' ) );
			}
			
			return pmpro_getMembershipLevelForUser( $user_id );
		}
		
		/**
		 *
		 * Update the PMPro Membership Level for the specified user ID
		 *
		 * @param WP_REST_Request $request
		 *
		 * @return bool|WP_Error
		 */
		public function updateLevelForUser( $request ) {
			
			if ( ! function_exists('pmpro_changeMembershipLevel') ) {
				return new WP_Error( 'rest_forbidden', esc_html__( 'Paid Memberships Pro plugin deactivated', 'pmpro-rest-api'));
			}
			
			$user_id = $request['user'];
			$level = $request['level'];
			
			$this->user = get_user_by( 'ID', $user_id );
			
			if ( empty( $this->user ) ) {
				
				return new WP_Error( 'rest_forbidden', esc_html__( 'Cannot check membership level for unknown/invalid user', 'pmpro-rest-api' ) );;
			}
			
			$this->logged_in = $this->user->exists();
			
			
			if ( false === $this->logged_in ) {
				
				return new WP_Error( 'rest_forbidden', esc_html__( 'User does not have access to the PMPro REST API', 'pmpro-rest-api' ) );
			}
			
			$level_obj = (array) pmpro_getLevel($level);
			$level_obj['membership_id'] = $level;
			$level_obj['user_id'] = $user_id;
			$level_obj['startdate'] =  current_time( 'mysql' );
			$level_obj['enddate'] =  '0000-00-00 00:00:00';

			return pmpro_changeMembershipLevel( $level_obj, $user_id );
		}
		
		/**
		 * Retrieve and initiate the class instance
		 *
		 * @return pmproRestAPI
		 */
		public static function get_instance() {
			
			if ( is_null( self::$instance ) ) {
				self::$instance = new self;
			}
			
			$class = self::$instance;
			
			return $class;
		}
		
		/**
		 * Load the required translation file for the add-on
		 */
		public function loadTranslation() {
			
			$locale = apply_filters( "plugin_locale", get_locale(), "pmpro-rest-api" );
			$mo     = "pmpro-rest-api-{$locale}.mo";
			
			// Paths to local (plugin) and global (WP) language files
			$local_mo  = plugin_dir_path( __FILE__ ) . "/languages/{$mo}";
			$global_mo = WP_LANG_DIR . "/pmpro-rest-api/{$mo}";
			
			// Load global version first
			load_textdomain( "pmpro-rest-api", $global_mo );
			
			// Load local version second
			load_textdomain( "pmpro-rest-api", $local_mo );
		}
		
		/**
		 * Connect to the license server using TLS 1.2
		 *
		 * @param $handle - File handle for the pipe to the CURL process
		 */
		public function forceTLS12( $handle ) {
			
			// set the CURL option to use.
			curl_setopt( $handle, CURLOPT_SSLVERSION, 6 );
		}
		
		/**
		 * Autoloader class for the plugin.
		 *
		 * @param string $class_name Name of the class being attempted loaded.
		 */
		public function __class_loader( $class_name ) {
			
			$classes = array(
				strtolower( get_class( $this ) ),
			);
			
			$plugin_classes = $classes;
			
			if ( in_array( strtolower( $class_name ), $plugin_classes ) && ! class_exists( $class_name ) ) {
				
				$name = strtolower( $class_name );
				
				$filename = dirname( __FILE__ ) . "/classes/class.{$name}.php";
				
				if ( file_exists( $filename ) ) {
					require_once $filename;
				}
				
			}
		} // End of autoloader method
		
		/**
		 * Stub function for activation activities
		 */
		public function activatePlugin() {
		
		}
		
		/**
		 * Stub function for deactivation activities
		 */
		public function deactivatePlugin() {
		
		}
	}
	
}
/**
 * Configure autoloader
 */
spl_autoload_register( array( pmproRestAPI::get_instance(), '__class_loader' ) );
add_action( 'plugins_loaded', 'pmproRestAPI::get_instance' );

register_activation_hook( __FILE__, array( pmproRestAPI::get_instance(), 'activatePlugin' ) );
register_deactivation_hook( __FILE__, array( pmproRestAPI::get_instance(), 'deactivatePlugin' ) );

/**
 * Configure one-click update while the plugin is outside of the WordPress.org repository
 * TODO: Remove once submitted to repository
 */
if ( ! class_exists( '\\PucFactory' ) ) {
	require_once( plugin_dir_path( __FILE__ ) . 'plugin-updates/plugin-update-checker.php' );
}

$plugin_updates = \PucFactory::buildUpdateChecker(
	'https://eighty20results.com/protected-content/pmpro-rest-api/metadata.json',
	__FILE__,
	'pmpro-rest-api'
);
