<?php
/*
Plugin Name: One Click Demo Import
Plugin URI: http://www.proteusthemes.com
Description: WordPress import made easy. Theme authors: Enable simple demo import for your theme demo data.
Version: 0.1-alpha
Author: ProteusThemes
Author URI: http://www.proteusthemes.com
License: GPL3
License URI: http://www.gnu.org/licenses/gpl.html
Text Domain: pt-ocdi
*/

// Path/URL to root of this plugin, with trailing slash
define( 'PT_OCDI_PATH', apply_filters( 'pt-ocdi/plugin_dir_path', plugin_dir_path( __FILE__ ) ) );
define( 'PT_OCDI_URL', apply_filters( 'pt-ocdi/plugin_dir_url', plugin_dir_url( __FILE__ ) ) );

// Current version of the plugin
define( 'PT_OCDI_VERSION', apply_filters( 'pt-ocdi/version', '0.1-alpha' ) );

// Include files
require PT_OCDI_PATH . 'inc/class-ocdi-helpers.php';
require PT_OCDI_PATH . 'inc/class-ocdi-importer.php';
require PT_OCDI_PATH . 'inc/class-ocdi-widget-importer.php';
require PT_OCDI_PATH . 'inc/class-ocdi-logger.php';

/**
 * One Click Demo Import class, so we don't have to worry about namespaces
 */
class PT_One_Click_Demo_Import {

	private $importer, $plugin_page, $importer_options, $import_files, $logger, $logger_options;

	function __construct() {

		// Actions
		add_action( 'admin_menu', array( $this, 'create_plugin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'wp_ajax_ocdi_prepare_import_data', array( $this, 'prepare_import_data_ajax_callback' ) );
		add_action( 'wp_ajax_ocdi_import_data', array( $this, 'import_data_ajax_callback' ) );
		add_action( 'wp_ajax_ocdi_import_widgets', array( $this, 'import_widgets_ajax_callback' ) );
		add_action( 'after_setup_theme', array( $this, 'setup_plugin_with_filter_data' ) );

	}


	/**
	 * Creates the plugin page and a submenu item in WP Appearance menu
	 */
	function create_plugin_page() {

		$this->plugin_page = add_theme_page( 'One Click Demo Import', 'Import Demo Data', 'import', 'pt-one-click-demo-import', array( $this, 'display_plugin_page' ) );

	}


	/**
	 * Plugin page display
	 */
	function display_plugin_page() {

	?>

		<div class="ocdi  wrap">
			<h2 class="ocdi__title"><span class="dashicons  dashicons-download"></span><?php esc_html_e( 'One Click Demo Import', 'pt-ocdi' ); ?></h2>
			<p>
				<?php esc_html_e( 'TODO: General description of demo import goes here...', 'pt-ocdi' ); ?>
			</p>

			<?php if ( empty( $this->import_files ) ) : ?>
				<div class="error  below-h2">
					<p>
						<?php esc_html_e( 'There are no import files available!', 'pt-ocdi' ); ?>
					</p>
				</div>
			<?php elseif ( 1 < count( $this->import_files ) ) : ?>
			<p>
				<select id="demo-import-files">
					<?php foreach ( $this->import_files as $index => $import_file ) : ?>
						<option value="<?php echo $index; ?>">
							<?php echo esc_html( $import_file['import_file_name'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>
			<?php endif; ?>

			<p>
				<button class="panel-save  button-primary  js-ocdi-import-data  ocdi__button" <?php disabled( true, empty( $this->import_files ) ); ?>><?php esc_html_e( 'Import Demo Data', 'pt-ocdi' ); ?></button>
			</p>

			<div class="js-ocdi-ajax-response  ocdi__response"></div>
		</div>

	<?php

	}


	/**
	 * Enqueue admin scripts (JS and CSS)
	 *
	 * @param $hook, holds info on which admin page you are currently looking at
	 */
	function admin_enqueue_scripts( $hook ) {

		// enqueue the scripts only on the plugin page
		if ( $this->plugin_page === $hook ) {

			wp_enqueue_script( 'ocdi-main-js', PT_OCDI_URL . 'assets/js/main.js' , array( 'jquery' ), PT_OCDI_VERSION );

			wp_localize_script( 'ocdi-main-js', 'ocdi',
				array(
					'ajax_url'     => admin_url( 'admin-ajax.php' ),
					'ajax_nonce'   => wp_create_nonce( 'ocdi-ajax-verification' )
				)
			);

			wp_enqueue_style( 'ocdi-main-css', PT_OCDI_URL . 'assets/css/main.css', array() , PT_OCDI_VERSION );

		}

	}


	/**
	 * AJAX download import file callback function
	 */
	function prepare_import_data_ajax_callback() {

		check_ajax_referer( 'ocdi-ajax-verification', 'security' );

		// Check if user has the WP capability to import data.
		if ( ! current_user_can( 'import' ) ) {

			wp_die( __( 'Your user role isn\'t high enough. You don\'t have permission to import demo data.', 'pt-ocdi' ) );

		}

		// Get selected file index or set it to the first file.
		$selected_index = empty( $_POST['selected'] ) ? 0 : absint( $_POST['selected'] );

		// Download the import files (content and widgets files) and save it to variable for later use
		$selected_import_files = OCDI_Helpers::download_import_files( $this->import_files[ $selected_index ] );

		// If there were no errors, then we can assume that the file was downloaded correctly
		$response                      = array();
		$response['import_file_paths'] = $selected_import_files;
		$response['message']           = sprintf(
			__( '%1$sThe import file: %2$s%3$s%4$s was %2$ssuccessfully downloaded%4$s! Continuing with demo import...%5$s', 'pt-ocdi' ),
			'<div class="ocdi__message  ocdi__message--success"><p>',
			'<strong>',
			$this->import_files[ $selected_index ]['import_file_name'],
			'</strong>',
			'</p></div>'
		);

		wp_send_json( $response );

	}


	/**
	 * AJAX import data callback function
	 */
	function import_data_ajax_callback() {

		check_ajax_referer( 'ocdi-ajax-verification', 'security' );

		// Check if user has the WP capability to import data.
		if ( ! current_user_can( 'import' ) ) {

			wp_die( __( 'Your user role isn\'t high enough. You don\'t have permission to import demo data.', 'pt-ocdi' ) );

		}

		// Get import file path parameter from the AJAX call
		$import_file_paths = empty( $_POST['import_file_paths'] ) ? '' : $_POST['import_file_paths'];

		// Demo import report, holds the output of demo import
		$import_report = '';

		// Import demo data
		if ( ! empty( $import_file_paths ) ) {

			ob_start();
				$this->importer->import( $import_file_paths['data'] );
			$import_report = ob_get_clean();

			// Create a log file with full details
			$this->logger->create_log_file();

		}

		// Response for finished demo data import
		$response = array();

		if ( ! empty( $import_file_paths['widgets'] ) ) {

			$response['import_widget_path'] = $import_file_paths['widgets'];

		}

		$response['message'] = $import_report . sprintf(
			__( '%sThe demo import has finished, widget import is next...%s', 'pt-ocdi' ),
			'<div class="ocdi__message  ocdi__message--success"><p>',
			'</p></div>'
		);

		wp_send_json( $response );
	}


	/**
	 * AJAX import widgets callback function
	 */
	function import_widgets_ajax_callback() {

		check_ajax_referer( 'ocdi-ajax-verification', 'security' );

		// Check if user has the WP capability to import data.
		if ( ! current_user_can( 'import' ) ) {

			wp_die( __( 'Your user role isn\'t high enough. You don\'t have permission to import demo data.', 'pt-ocdi' ) );

		}

		// Get import file path parameter from the AJAX call
		$import_widget_path = empty( $_POST['import_widget_path'] ) ? '' : $_POST['import_widget_path'];

		// Import widgets
		if ( ! empty( $import_widget_path ) ) {

			$widget_importer = new OCDI_Widget_Importer();
			$results = $widget_importer->import_widgets( $import_widget_path );
			$widget_importer->format_results_for_display( $results );

		}

		wp_die(
			sprintf(
				__( '%1$s%3$sThat\'s it, all done!%4$s%2$sThe demo import has finished. Please check your page and make sure that everything has imported correctly. If it did, you can deactivate the %3$sOne Click Demo Import%4$s plugin, because it has done its job.%5$s', 'pt-ocdi' ),
				'<div class="ocdi__message  ocdi__message--success"><p>',
				'<br>',
				'<strong>',
				'</strong>',
				'</p></div>'
			)
		);

	}


	/**
	 * Get data from filters, after the theme has loaded and instantiate the importer
	 */
	function setup_plugin_with_filter_data() {

		// Get info of import data files and filter it
		$this->import_files = OCDI_Helpers::validate_import_file_info( apply_filters( 'pt-ocdi/import_files', array() ) );

		// Importer options array
		$this->importer_options = apply_filters( 'pt-ocdi/importer_options', array(
			'fetch_attachments' => true,
		) );

		// Create importer instance with proper parameters
		$this->importer = new OCDI_Importer( $this->importer_options );

		// Logger options for the importer
		$this->logger_options = apply_filters( 'pt-ocdi/logger_options', array(
			'logger_min_level' => 'notice',
		) );

		// Set the logger and set it to the importer
		$this->logger            = new OCDI_Logger();
		$this->logger->min_level = $this->logger_options['logger_min_level'];
		$this->importer->set_logger( $this->logger );

	}

}

new PT_One_Click_Demo_Import();
