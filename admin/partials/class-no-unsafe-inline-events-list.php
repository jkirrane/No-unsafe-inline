<?php
/**
 * The class used to render the table in events whitelist tab.
 *
 * @link       https://profiles.wordpress.org/mociofiletto/
 * @since      1.0.0
 *
 * @package    No_Unsafe_Inline
 * @subpackage No_Unsafe_Inline/admin
 */

use Highlight\Highlighter;
use NUNIL\Nunil_Lib_Db as DB;

defined( 'ABSPATH' ) || die( 'you do not have acces to this page!' );

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    No_Unsafe_Inline
 * @subpackage No_Unsafe_Inline/admin
 * @author     Giuseppe Foti <foti.giuseppe@gmail.com>
 */
class No_Unsafe_Inline_Events_List extends WP_List_Table {

	/** Class constructor */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => __( 'nunil-evh-script', 'no-unsafe-inline' ),
				'plural'   => __( 'nunil-evh-scripts', 'no-unsafe-inline' ),
				'ajax'     => false, // should this table support ajax?
			)
		);

	}

	/**
	 * Render the bulk edit checkbox
	 *
	 * @param array $item Query row.
	 *
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="evh-select[]" value="%s" />',
			$item['ID']
		);
	}

	/**
	 * Returns an associative array containing the bulk action.
	 *
	 * @since 1.0.0
	 */
	public function get_bulk_actions() {
		return array(
			'whitelist-bulk' => __( 'WhiteList', 'no-unsafe-inline' ),
			'blacklist-bulk' => __( 'BlackList', 'no-unsafe-inline' ),
			'delete-bulk'    => __( 'Delete', 'no-unsafe-inline' ),
		);

	}

	/**
	 * Process bulk actions (and singolar action) during prepare_items.
	 *
	 * @since 1.0.0
	 */
	public function process_bulk_action() {
		/**
		 * Security check for bulk actions.
		 * For single actions, I will check nonce insede switch because
		 * it isn't in the post array.
		 */
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'User is not allowed to perform this action', 'no-unsafe-inline' ) );
		}
		if ( isset( $_POST['_wpnonce'] ) && ! empty( $_POST['_wpnonce'] ) ) {

			$nonce  = filter_input( INPUT_POST, '_wpnonce', FILTER_SANITIZE_STRING );
			$action = 'bulk-' . $this->_args['plural'];

			if ( ! wp_verify_nonce( $nonce, $action ) ) {
				wp_die( esc_html__( 'Nope! Security check failed!', 'no-unsafe-inline' ) );
			}
			$action = $this->current_action();

		} elseif ( isset( $_GET['action'] ) && isset( $_GET['_wpnonce'] ) && ! empty( $_GET['_wpnonce'] ) ) {
				$nonce  = filter_input( INPUT_GET, '_wpnonce', FILTER_SANITIZE_STRING );
				$action = ( isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '' );
		} else {
				$action = '';
		}

		switch ( $action ) {

			case 'whitelist':
				if ( ! wp_verify_nonce( $nonce, 'whitelist_evh_script_nonce' ) ) {
					wp_die( esc_html__( 'Nope! Security check failed!', 'no-unsafe-inline' ) );
				}
				if ( isset( $_GET['script_id'] ) ) {
					$script_id = intval( $_GET['script_id'] );
					$affected  = DB::evh_whitelist( $script_id );
				}
				break;

			case 'whitelist-bulk':
				if ( isset( $_POST['evh-select'] ) ) {
					$selected = sanitize_text_field( wp_unslash( $_POST['evh-select'] ) );
					$affected = DB::evh_whitelist( $selected );
				}
				break;

			case 'blacklist':
				if ( ! wp_verify_nonce( $nonce, 'whitelist_evh_script_nonce' ) ) {
					wp_die( esc_html__( 'Nope! Security check failed!', 'no-unsafe-inline' ) );
				}
				if ( isset( $_GET['script_id'] ) ) {
					$script_id = intval( $_GET['script_id'] );
					$affected  = DB::evh_whitelist( $script_id, false );
				}
				break;

			case 'blacklist-bulk':
				if ( isset( $_POST['evh-select'] ) ) {
					$selected = sanitize_text_field( wp_unslash( $_POST['evh-select'] ) );
					$affected = DB::evh_whitelist( $selected, false );
				}
				break;

			case 'delete':
				if ( ! wp_verify_nonce( $nonce, 'delete_evh_script_nonce' ) ) {
					wp_die( esc_html__( 'Nope! Security check failed!', 'no-unsafe-inline' ) );
				}
				if ( isset( $_GET['script_id'] ) ) {
					$script_id = intval( $_GET['script_id'] );
					$affected  = DB::evh_delete( $script_id );
				}
				break;

			case 'delete-bulk':
				if ( isset( $_POST['evh-select'] ) ) {
					$selected = sanitize_text_field( wp_unslash( $_POST['evh-select'] ) );
					$affected = DB::evh_delete( $selected );
				}
				break;

			case 'uncluster':
				if ( ! wp_verify_nonce( $nonce, 'uncluster_evh_script_nonce' ) ) {
					wp_die( esc_html__( 'Nope! Security check failed!', 'no-unsafe-inline' ) );
				}
				if ( isset( $_GET['script_id'] ) ) {
					$script_id = intval( $_GET['script_id'] );
					$affected  = DB::evh_uncluster( $script_id );
				}
				break;

			default:
				// do nothing or something else.
				break;
		}

		return;
	}

	/**
	 * Render the script column
	 *
	 * @param array $item Item with row's data.
	 *
	 * @return string
	 */
	public function column_script( $item ) {
		$admin_page_url = admin_url( 'options-general.php' );

		$hl = new \Highlight\Highlighter();
		$hl->setAutodetectLanguages( array( 'javascript', 'css', 'json', 'wasm' ) );
		$highlighted = $hl->highlightAuto( $item['script'] );
		$code        = '<div class="nunil-code-wrapper-' . $item['ID'] . '"><div class="code-accordion-' . $item['ID'] . '"><h4>' . esc_html__( 'View code', 'no-unsafe-inline' ) . '</h4>';
		$code       .= "<div><pre class=\"nunil-script-code\"><code class=\"hljs {$highlighted->language}\">";
		$code       .= $highlighted->value;
		$code       .= '</code></pre></div></div></div>';

		$actions = array();
		// row action to delete inline script.

		$query_args_delete_evh_script = array(
			'page'      => wp_unslash( $_REQUEST['page'] ),
			'tab'       => 'events',
			'action'    => 'delete',
			'script_id' => absint( $item['ID'] ),
			'_wpnonce'  => wp_create_nonce( 'delete_evh_script_nonce' ),
		);

		$delete_evh_script_link = esc_url( add_query_arg( $query_args_delete_evh_script, $admin_page_url ) );

		$actions['delete'] = '<a href="' . $delete_evh_script_link . '">' . __( 'Delete', 'no-unsafe-inline' ) . '</a>';

		return sprintf( '%1$s %2$s', $code, $this->row_actions( $actions ) );
	}

	/**
	 * Render the whitelist column
	 *
	 * @param array $item Item with row's data.
	 *
	 * @return string
	 */
	public function column_whitelist( $item ) {

		$admin_page_url = admin_url( 'options-general.php' );

		$actions = array();

		$query_args_whitelist_evh_script = array(
			'page'      => 'no-unsafe-inline',
			'tab'       => 'events',
			'script_id' => absint( $item['ID'] ),
			'_wpnonce'  => wp_create_nonce( 'whitelist_evh_script_nonce' ),
		);

		if ( '0' === $item['whitelist'] ) {
			$query_args_whitelist_evh_script['action'] = 'whitelist';
			$whitelist_evh_script_link                 = esc_url( add_query_arg( $query_args_whitelist_evh_script, $admin_page_url ) );
			$actions['whitelist']                      = '<a href="' . $whitelist_evh_script_link . '">' . __( 'WhiteList', 'no-unsafe-inline' ) . '</a>';
			$wl_text                                   = '<p class="blacklist">' . __( 'BL', 'no-unsafe-inline' ) . '</p>';
		} else {
			$query_args_whitelist_evh_script['action'] = 'blacklist';
			$blacklist_evh_script_link                 = esc_url( add_query_arg( $query_args_whitelist_evh_script, $admin_page_url ) );
			$actions['blacklist']                      = '<a href="' . $blacklist_evh_script_link . '">' . __( 'BlackList', 'no-unsafe-inline' ) . '</a>';
			$wl_text                                   = '<p class="whitelist">' . __( 'WL', 'no-unsafe-inline' ) . '</p>';
		}

		return sprintf( '%1$s %2$s', $wl_text, $this->row_actions( $actions ) );
	}

	/**
	 * Render the clustername column
	 *
	 * @param array $item Item with row's data.
	 *
	 * @return string
	 */
	public function column_clustername( $item ) {

		$admin_page_url = admin_url( 'options-general.php' );

		if ( 'Unclustered' !== $item['clustername'] ) {
			$actions = array();

			$query_args_uncluster_evh_script = array(
				'page'      => wp_unslash( $_REQUEST['page'] ),
				'tab'       => 'events',
				'action'    => 'uncluster',
				'script_id' => absint( $item['ID'] ),
				'_wpnonce'  => wp_create_nonce( 'uncluster_evh_script_nonce' ),
			);

			$uncluster_evh_script_link = esc_url( add_query_arg( $query_args_uncluster_evh_script, $admin_page_url ) );

			$actions['uncluster'] = '<a href="' . $uncluster_evh_script_link . '">' . __( 'Uncluster', 'no-unsafe-inline' ) . '</a>';

			return sprintf( '%1$s %2$s', $item['clustername'], $this->row_actions( $actions ) );
		} else {
			return $item['clustername'];
		}

	}

	/**
	 * Render the pages column
	 *
	 * @param array $item Item with row's data.
	 *
	 * @return string
	 */
	public function column_pages( $item ) {
		$hl          = new \Highlight\Highlighter();
		$highlighted = $hl->highlightAuto( $item['pages'] );
		$code        = '<div class="pages-wrapper-' . $item['ID'] . '"><div class="pages-accordion-' . $item['ID'] . '"><h5>' . esc_html__( 'View pages', 'no-unsafe-inline' ) . '</h5>';
		$code       .= "<div><pre class=\"nunil-pages-code\"><code class=\"hljs {$highlighted->language}\">";
		$code       .= $highlighted->value;
		$code       .= '</code></pre></div></div></div>';

		return $code;
	}

	/**
	 * Process any column for which no special method is defined.
	 *
	 * @since 1.0.0
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'tagname':
			case 'tagid':
			case 'event_attribute':
			case 'clustername':
			case 'whitelist':
			case 'lastseen':
			case 'occurences':
				return $item[ $column_name ];
			default:
				return print_r( $item, true ); // Show the whole array for troubleshooting purposes.
		}
	}

	/**
	 * Columns to make sortable.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		$sortable_columns = array(
			'tagname'         => array( 'tagname', true ),
			'tagid'           => array( 'tagid', false ),
			'event_attribute' => array( 'event_attribute', false ),
			'clustername'     => array( 'clustername', false ),
			'whitelist'       => array( 'whitelist', false ),
			'occurences'      => array( 'occurences', false ),
			'lastseen'        => array( 'lastseen', false ),

		);

		return $sortable_columns;
	}

	/**
	 * Define which columns are hidden
	 *
	 * @return array
	 */
	public function get_hidden_columns() {
		$hidden_columns = array(
			'pages' => array( 'pages', false ),
			'tagid' => array( 'tagid', false ),
		);
		return $hidden_columns;
	}

	/**
	 * Associative array of columns
	 *
	 * @return array
	 */
	public function get_columns() {
		$columns = array(
			'cb'              => '<input type="checkbox" />',
			'script'          => __( 'Script', 'no-unsafe-inline' ),
			'tagname'         => __( 'TagName', 'no-unsafe-inline' ),
			'tagid'           => __( 'TagId', 'no-unsafe-inline' ),
			'event_attribute' => __( 'Event', 'no-unsafe-inline' ),
			'clustername'     => __( 'Cluster', 'no-unsafe-inline' ),
			'occurences'      => __( 'Cl.\'s Numerosity', 'no-unsafe-inline' ),
			'whitelist'       => __( 'WhiteList', 'no-unsafe-inline' ),
			'pages'           => __( 'Pages', 'no-unsafe-inline' ),
			'lastseen'        => __( 'Last Seen', 'no-unsafe-inline' ),
		);

		return $columns;
	}

	/**
	 * Defines two arrays controlling the behaviour of the table.
	 *
	 * @since 1.0.0
	 */
	public function prepare_items() {

		$search = ( isset( $_REQUEST['s'] ) ) ? $_REQUEST['s'] : false;

		$this->_column_headers = $this->get_column_info();

		$this->process_bulk_action();

		$user          = get_current_user_id();
		$screen        = get_current_screen();
		$screen_option = $screen->get_option( 'per_page', 'option' );
		$per_page      = get_user_meta( $user, $screen_option, true );
		if ( empty( $per_page ) || $per_page < 1 ) {
			$per_page = $screen->get_option( 'per_page', 'default' );
		}

		$paged = isset( $_REQUEST['paged'] ) ? max( 0, intval( $_REQUEST['paged'] - 1 ) * $per_page ) : 0;

		$order = ( isset( $_REQUEST['order'] ) && in_array( $_REQUEST['order'], array( 'ASC', 'DESC', 'asc', 'desc' ) ) ) ? $_REQUEST['order'] : 'ASC';

		$orderby = 'ORDER BY ';

		if ( isset( $_REQUEST['orderby'] ) && in_array( $_REQUEST['orderby'], array_keys( $this->get_sortable_columns() ) ) ) {

			switch ( $_REQUEST['orderby'] ) {
				case 'tagid':
					$orderby .= "tagid $order ";
					break;
				case 'tagname':
					$orderby .= "tagname $order, ";
					break;
				case 'event_attribute':
					$orderby .= "event_attribute $order, ";
					break;
				case 'clustername':
					$orderby .= "clustername $order ";
					break;
				case 'whitelist':
					$orderby .= "whitelist $order ";
					break;
				case 'occurences':
					$orderby .= "occurences $order ";
					break;
				case 'lastseen':
					$orderby .= "lastseen $order ";
					break;
				default:
					$orderby .= "tagid $order ";
			}
		} else {
			$orderby .= 'ID ASC ';
		}

		$total_items = DB::get_events_total_num();

		$data = DB::get_events_list( $orderby, $per_page, $paged, $search );

		$current_page = $this->get_pagenum();

		$this->items = $data;

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			)
		);
	}
}
