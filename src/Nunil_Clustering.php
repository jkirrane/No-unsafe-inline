<?php
/**
 * Clustering of scripts
 *
 * This class is used for clustering scripts, trying to find script that cannot work without unsafe-inline
 *
 * @package No_unsafe-inline
 * @link    https://wordpress.org/plugins/no-unsafe-inline/
 * @since   1.0.0
 */

namespace NUNIL;

use Beager\Nilsimsa;

use Rubix\ML\Datasets\Unlabeled;
use Rubix\ML\Clusterers\DBSCAN;
use Rubix\ML\Graph\Trees\BallTree;

use NUNIL\Nunil_Lib_Db as DB;
use NUNIL\Nunil_Lib_Log as Log;

/**
 * Class with methods used to cluster scripts
 *
 * @package No_unsafe-inline
 * @since   1.0.0
 */
class Nunil_Clustering {

	/**
	 * Performs DBSCAN and return an array of clustered arrays
	 *
	 * @since 1.0.0
	 *
	 * @param array<\stdClass> $obj_hashes The array of obj generated by $wpdb->get_results.
	 * @param string           $table The scripts table to be clustered: one of inline_scripts or event_handlers (prefixed).
	 * @return array<int, int> The return value of predict() is an array containing the predictions in the same order that they were indexed in the dataset
	 */
	private static function make_db_scan( $obj_hashes, $table ) {
		$samples = array();

		$gls = new Nunil_Global_Settings();
		// building array of samples.
		foreach ( $obj_hashes as $hash ) {
			$samples[] = $hash->nilsimsa;
		}

		// Create RubixML Unlabelled Dataset.
		$dataset = new Unlabeled( $samples );
		switch ( $table ) {
			case 'inline_scripts':
				$dbscan = new DBSCAN( $gls->dbscan_epsilon_inl, $gls->dbscan_minsamples_inl, new BallTree( 20, new Nunil_Hamming_Distance() ) );
				break;
			case 'event_handlers':
				$dbscan = new DBSCAN( $gls->dbscan_epsilon_inl, $gls->dbscan_minsamples_evh, new BallTree( 20, new Nunil_Hamming_Distance() ) );
				break;

			default:
				$dbscan = new DBSCAN( $gls->dbscan_epsilon_inl, $gls->dbscan_minsamples_inl, new BallTree( 20, new Nunil_Hamming_Distance() ) );
				break;
		}

		$results = $dbscan->predict( $dataset );

		return $results;
	}

	/**
	 * Create a random clustername
	 *
	 * @since 1.1.0
	 * @access private
	 * @param int $cluster The Cluster created by Estimator->predict().
	 * @return string
	 */
	private static function create_clustername( $cluster ) {
		// using ClusterNames as Cl_000000001.
		if ( -1 === $cluster ) {
			$cluster_name = 'Unclustered';
		} else {
			$cluster_name = 'Cl_' . str_pad( strval( random_int( 1, 999999999 ) ), 9, '0', STR_PAD_LEFT );
		}
		return $cluster_name;
	}

	/**
	 * Returns an array of unique clusternames.
	 * The key of each element is the cluster returned by predict().
	 *
	 * @since 1.1.0
	 * @access private
	 * @param array<int, int> $predicted The return value of predict().
	 * @return array<int, string>
	 */
	private static function get_clusternames( $predicted ) {
		$clusternames = array();
		$unique       = array_unique( $predicted, SORT_NUMERIC );
		foreach ( $unique as $cluster ) {
			$clusternames[ $cluster ] = self::create_clustername( $cluster );
		}
		return $clusternames;
	}

	/**
	 * Puts the cluster value in database.
	 *
	 * @since 1.0.0
	 *
	 * @param string           $table The scripts table to be clustered: one of inline_scripts or event_handlers.
	 * @param array<\stdClass> $obj_collection the get-results array of obj made of [ID] [nilsimsa hexDigest].
	 * @param array<int, int>  $dbscan_results An array of predicted clusters by make_db_scan().
	 *
	 * @return int The number of clusters built
	 */
	private static function cluster_digests( $table, $obj_collection, $dbscan_results ) {
		$clusternames = self::get_clusternames( $dbscan_results );

		$clusters_numbers = count( $clusternames );

		$dbscan_array = array();

		$has_noise = false;

		foreach ( $dbscan_results as $hash_index => $cluster ) {
			if ( -1 === $cluster ) {
				$has_noise = true;
			}

			$data = array(
				'clustername' => $clusternames[ $cluster ],
			);

			$where = array(
				'ID' => $obj_collection[ $hash_index ]->ID,
			);

			DB::update_cluster( $table, $data, $where );
		}

		if ( true === $has_noise ) {
			--$clusters_numbers;
		}

		self::whitelist_cluster( $table );

		return $clusters_numbers;
	}

	/**
	 * Whitelist all hashes in cluster if one of the hashes is whitelist
	 *
	 * @since 1.1.0
	 * @param string $table The scripts table to be clustered: one of inline_scripts or event_handlers.
	 * @return void
	 */
	private static function whitelist_cluster( $table ): void {
		// If one of the elements is whitelisted, we whitelist all the cluster.
		$clusters = DB::get_clusters_in_table( $table );
		foreach ( $clusters as $cluster ) {
			$wl = DB::get_max_wl_in_cluster( $table, $cluster->clustername );

			$data  = array(
				'whitelist' => intval( $wl ),
			);
			$where = array(
				'clustername' => $cluster->clustername,
			);
			DB::update_cluster( $table, $data, $where );
		}
	}

	/**
	 * Performs clustering by db scan
	 *
	 * @since 1.0.0
	 * @access public
	 * @return array{type: string, report:string} A report of performed operarions.
	 */
	public static function cluster_by_dbscan() {
		$gls = new Nunil_Global_Settings();

		set_time_limit( $gls->clustering_time_limit );

		$start_time = microtime( true );

		$result_string = '<br><b> --- ' . esc_html__( 'CLUSTERING DATABASE', 'no-unsafe-inline' ) . ' --- </b><br>';

		$result_string = $result_string . esc_html__( 'Start time: ', 'no-unsafe-inline' ) . $start_time . '<br>';

		$scripts_tables = array(
			array(
				'table'              => 'inline_scripts',
				'segmentation_field' => 'directive',
			),
			array(
				'table'              => 'event_handlers',
				'segmentation_field' => 'event_attribute',
			),
		);

		foreach ( $scripts_tables as $tbl ) {
			// translators:: %s is the table internal name.
			$result_string = $result_string . '<br>' . sprintf( esc_html__( 'Clustering %s', 'no-unsafe-inline' ), '<b>' . $tbl['table'] . '</b>' ) . '<br>';

			$table = $tbl['table'];

			switch ( $table ) {
				case 'inline_scripts':
					$radius     = $gls->dbscan_epsilon_inl;
					$minSamples = $gls->dbscan_minsamples_inl;
					break;
				case 'event_handlers':
					$radius     = $gls->dbscan_epsilon_evh;
					$minSamples = $gls->dbscan_minsamples_evh;
					break;
			}

			$substr        = 'DBSCAN params: radius: %s - minDensity: %s';
			$result_string = $result_string . '<br>' . sprintf(
				esc_html__( $substr, 'no-unsafe-inline' ),
				$radius,
				$minSamples
			);

			$seg_fields = DB::get_segmentation_values( $tbl['segmentation_field'], $tbl['table'] );

			foreach ( $seg_fields as $segment ) {
				$tagnames = DB::get_tagnames( $tbl['segmentation_field'], $segment[ $tbl['segmentation_field'] ], $table );

				foreach ( $tagnames as $tagname ) {
					$obj_collection = DB::get_nilsimsa_hashes( $table, $tbl['segmentation_field'], $segment[ $tbl['segmentation_field'] ], $tagname['tagname'], null );
					if ( $minSamples <= count( $obj_collection ) ) {
						$result_string   = $result_string . '<br><b>' . $segment[ $tbl['segmentation_field'] ] . '</b> - <b><i>' . $tagname['tagname'] . '</i></b><br>';
						$result_string   = $result_string . esc_html__( 'Processed hashes: ', 'no-unsafe-inline' ) . count( $obj_collection ) . '<br>';
						$dbscan_results  = self::make_db_scan( $obj_collection, $tbl['table'] );
						$clustered_built = self::cluster_digests( $table, $obj_collection, $dbscan_results );

						$result_string = $result_string . esc_html__( 'Clusters built: ', 'no-unsafe-inline' ) . strval( $clustered_built ) . '<br>';

						$result_string = $result_string . ' ------- $$$ ------- <br>';
					}
				}
			}
			$result_string = $result_string . 'End clustering <b>' . $tbl['table'] . '</b><br>';
			Log::info( 'Performed clustering on ' . $tbl['table'] );
		}
		$end_time = microtime( true );

		$result_string = $result_string . esc_html__( 'End time: ', 'no-unsafe-inline' ) . $end_time . '<br>';

		$execution_time = ( $end_time - $start_time );

		$result_string = $result_string . esc_html__( 'Execution time of script (sec): ', 'no-unsafe-inline' ) . $execution_time . '<br>';

		$result['type']   = 'success';
		$result['report'] = $result_string;

		// Destroy cache, after reclustering.
		$cache_group = 'no-unsafe-inline';
		$cache_key   = 'inline_rows';
		wp_cache_delete( $cache_key, $cache_group );
		$cache_key = 'events_rows';
		wp_cache_delete( $cache_key, $cache_group );

		return $result;
	}
}
