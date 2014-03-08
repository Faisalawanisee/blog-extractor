<?php

/**
 * Blog Extract
 */
class Blog_Extract extends WP_CLI_Command {

	/**
	 * Blog extract
	 *
	 * ## OPTIONS
	 *
	 * <blog-id>
	 * : ID of blog to extract
	 *
	 * [--v]
	 * : Verbose
	 *
	 * ## EXAMPLES
	 *
	 *     wp extract blog 3
	 */
	function blog( $args, $assoc_args ) {
		$v = isset( $assoc_args['v'] );

		if ( ! is_multisite() ) {
			WP_CLI::error( "This is a multisite command only." );
		}
		$blogid = $args[0];
		if ( ! ( $details = get_blog_details( $blogid ) ) ) {
			WP_CLI::error( "Given blog id is invalid." );
			return;
		}
		switch_to_blog( $blogid );

		global $wpdb;

		$blog_tables   = $wpdb->tables('blog');

		/************************************\
		                             DATABASE
		\************************************/

		$tmp_users = "{$wpdb->prefix}users";
		$tmp_usermeta = "{$wpdb->prefix}usermeta";
		$blog_tables['users'] = $tmp_users;
		$blog_tables['usermeta'] = $tmp_usermeta;

		// @debug let's take a look
		// print_r( $blog_tables );

		$users = wp_list_pluck( get_users(), 'ID' );
		foreach( get_super_admins() as $username ) {
			$users[] = get_user_by( 'login', $username )->ID;
		}
		$users = array_unique( $users );

		$userlist = implode( ',', $users );


		if ( $v ) {
			WP_CLI::line( 'Begin copying user tables' );
		}

		// duplicate global user tables
		// delete unnecessary rows (probably not performant on large data)
		$wpdb->query( "create table if not exists {$tmp_users} like {$wpdb->users}" );
		$check = $wpdb->get_col( "select * from {$tmp_users}" );
		if ( empty( $check ) ) {
			if ( $v ) {
				WP_CLI::line( 'copying main users table' );
			}
			$wpdb->query( "insert into {$tmp_users} select * from {$wpdb->users}" );
		}
		$wpdb->query( "delete from {$tmp_users} where ID NOT IN ({$userlist})" );

		$wpdb->query( "create table if not exists {$tmp_usermeta} like {$wpdb->usermeta}" );
		$check = $wpdb->get_col( "select * from {$tmp_usermeta}" );
		if ( empty( $check ) ) {
			if ( $v ) {
				WP_CLI::line( 'copying main usermeta table' );
			}
			$wpdb->query( "insert into {$tmp_usermeta} select * from {$wpdb->usermeta}" );
		}
		$wpdb->query( "delete from {$tmp_usermeta} where user_id NOT IN ({$userlist})" );

		if ( $v ) {
			WP_CLI::line( 'Begin exporting tables' );
		}
		$tablelist = implode( ' ', $blog_tables );
		$sql_file = 'database.sql';
		shell_exec( "mysqldump -u ". DB_USER ." -p". DB_PASSWORD ." ". DB_NAME ." {$tablelist} > {$sql_file}" );

		if ( file_exists( ABSPATH . $sql_file ) ) {
			if ( ( $filesize = filesize( ABSPATH . $sql_file ) ) > 0 ) {
				$filesize = self::file_size_convert( $filesize );
				if ( $v ) {
					WP_CLI::line( 'Database tables exported' );
				}
				$wpdb->query( "drop table if exists {$tmp_users}, {$tmp_usermeta}" );
			} else {
				unlink( ABSPATH . $sql_file );
				WP_CLI::error( 'There was an error exporting the archive.' );
			}
		} else {
			WP_CLI::error( 'There was an error exporting the archive.' );
		}

		/************************************\
		                                FILES
		\************************************/

		$export_dirs = array( ABSPATH . $sql_file );

		// uploads
		$upload_dir = wp_upload_dir();
		$export_dirs[] = $upload_dir['basedir'];

		// plugins
		$plugins = get_option( 'active_plugins' );
		$plugins = array_map( function($i) {
			$parts = explode( '/', $i );
			$root = array_shift( $parts );
			return WP_CONTENT_DIR .'/plugins/'. $root;
		}, $plugins );
		$export_dirs = array_merge( $export_dirs, $plugins );

		// network plugins
		$networkplugins = wp_get_active_network_plugins();
		$networkplugins = array_map( function($i) {
			$parts = explode( '/', str_replace( WP_CONTENT_DIR .'/plugins/', '', $i ) );
			$root = array_shift( $parts );
			return WP_CONTENT_DIR .'/plugins/'. $root;
		}, $networkplugins );
		$export_dirs = array_merge( $export_dirs, $networkplugins );

		// mu plugins
		$muplugins = wp_get_mu_plugins();
		$export_dirs = array_merge( $export_dirs, $muplugins );

		// mu plugins
		$dropins = array_keys( get_dropins() );
		$dropins = array_map( function($i) {
			return WP_CONTENT_DIR .'/'. $i;
		}, $dropins );
		$export_dirs = array_merge( $export_dirs, $dropins );


		// theme(s)
		$themes = array_unique( array( get_stylesheet(), get_template() ) );
		$themes = array_map( function($i) { return WP_CONTENT_DIR. get_raw_theme_root( get_stylesheet() ) .'/' . $i;}, $themes );
		$export_dirs = array_merge( $export_dirs, $themes );

		// remove ABSPATH. makes the export more friendly when extracted
		$exports = array_map( function($i) {
			return '"'. str_replace( ABSPATH, '', $i ) .'"';
		}, $export_dirs );

		// @debug let's take a look
		// print_r( $exports );

		// @todo make this user-set
		$export_file = "archive-{$blogid}.tar.gz";

		// GOOD!
		$abspath = ABSPATH;
		$exports = implode( ' ', $exports );
		if ( $v ) {
			WP_CLI::line( 'Begin archiving files' );
		}
		shell_exec( "cd {$abspath}; tar -cvf {$export_file} {$exports}" );

		if ( file_exists( ABSPATH . $export_file ) ) {
			if ( ( $filesize = filesize( ABSPATH . $export_file ) ) > 0 ) {
				// sql dump was archived, remove regular file
				unlink( ABSPATH . $sql_file );

				$filesize = self::file_size_convert( $filesize );
				WP_CLI::success( "$export_file created! ($filesize)" );

				$prefix = WP_CLI::colorize( "%P{$wpdb->prefix}%n" );
				WP_CLI::line( 'In your new install, set the database prefix to '. $prefix );
				WP_CLI::line( 'You\'ll also need to do a search-replace for the url change' );

				$old_url = untrailingslashit( $details->domain. $details->path );
				WP_CLI::line( '=========================================' );

				WP_CLI::line( "# update URLs" );
				WP_CLI::line( "wp search-replace {$old_url} NEWURL" );
				WP_CLI::line( "# move the uploads to the typical directory" );
				WP_CLI::line( "mv wp-content/uploads/sites/{$blogid}/* wp-content/uploads/" );
				WP_CLI::line( "# remove the old directory" );
				WP_CLI::line( "rm -rf wp-content/uploads/sites/" );
				WP_CLI::line( "# update database" );
				WP_CLI::line( "wp search-replace wp-content/uploads/sites/{$blogid}/ wp-content/uploads/" );

				WP_CLI::line( '=========================================' );


			} else {
				unlink( ABSPATH . $export_file );
				WP_CLI::error( 'There was an error creating the archive.' );
			}
		} else {
			WP_CLI::error( 'Unable to create the archive.' );
		}

	}

	// helper to convert filesize
	private function file_size_convert( $bytes ) {
		$bytes = floatval( $bytes );
		$arBytes = array(
			array( 'unit' => 'TB', 'value' => pow(1024, 4) ),
			array( 'unit' => 'GB', 'value' => pow(1024, 3) ),
			array( 'unit' => 'MB', 'value' => pow(1024, 2) ),
			array( 'unit' => 'KB', 'value' => 1024 ),
			array( 'unit' => 'B', 'value' => 1 ),
		);

		foreach ( $arBytes as $arItem ) {
			if( $bytes >= $arItem['value'] ) {
				$result = $bytes / $arItem['value'];
				$result = strval( round( $result, 2 ) ) . ' '. $arItem['unit'];
				break;
			}
		}
		return $result;
	}
}

WP_CLI::add_command( 'extract', 'Blog_Extract' );