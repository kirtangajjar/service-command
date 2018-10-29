<?php

namespace EE\Service\Utils;

use EE;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Boots up the container if it is stopped or not running.
 * @throws EE\ExitException
 */
function nginx_proxy_check() {

	$proxy_type = EE_PROXY_TYPE;

	$config_80_port  = \EE\Utils\get_config_value( 'proxy_80_port', 80 );
	$config_443_port = \EE\Utils\get_config_value( 'proxy_443_port', 443 );

	if ( 'running' === EE::docker()::container_status( $proxy_type ) ) {
		$launch_80_test  = EE::launch( 'docker inspect --format \'{{ (index (index .NetworkSettings.Ports "80/tcp") 0).HostPort }}\' ee-global-nginx-proxy' );
		$launch_443_test = EE::launch( 'docker inspect --format \'{{ (index (index .NetworkSettings.Ports "80/tcp") 0).HostPort }}\' ee-global-nginx-proxy' );

		if ( $config_80_port !== trim( $launch_80_test->stdout ) || $config_443_port !== trim( $launch_443_test->stdout ) ) {
			EE::error( "Ports of current running nginx-proxy and ports specified in EasyEngine config file don't match." );
		}
	}

	/**
	 * Checking ports.
	 */
	$port_80_status  = \EE\Utils\get_curl_info( 'localhost', $config_80_port, true );
	$port_443_status = \EE\Utils\get_curl_info( 'localhost', $config_443_port, true );

	// if any/both the port/s is/are occupied.
	if ( ! ( $port_80_status && $port_443_status ) ) {
		EE::error( "Cannot create/start proxy container. Please make sure port $config_80_port and $config_443_port are free." );
	} else {

		$fs = new Filesystem();

		create_global_volumes();

		if ( ! $fs->exists( EE_ROOT_DIR . '/services/docker-compose.yml' ) ) {
			generate_global_docker_compose_yml( $fs );
		}

		$EE_ROOT_DIR = EE_ROOT_DIR;
		if ( ! EE::docker()::docker_network_exists( GLOBAL_BACKEND_NETWORK ) &&
		     ! EE::docker()::create_network( GLOBAL_BACKEND_NETWORK ) ) {
			EE::error( 'Unable to create network ' . GLOBAL_BACKEND_NETWORK );
		}
		if ( ! EE::docker()::docker_network_exists( GLOBAL_FRONTEND_NETWORK ) &&
		     ! EE::docker()::create_network( GLOBAL_FRONTEND_NETWORK ) ) {
			EE::error( 'Unable to create network ' . GLOBAL_FRONTEND_NETWORK );
		}
		if ( EE::docker()::docker_compose_up( EE_ROOT_DIR . '/services', [ 'global-nginx-proxy' ] ) ) {
			$fs->dumpFile( "$EE_ROOT_DIR/services/nginx-proxy/conf.d/custom.conf", file_get_contents( EE_ROOT . '/templates/custom.conf.mustache' ) );
			EE::success( "$proxy_type container is up." );
		} else {
			EE::error( "There was some error in starting $proxy_type container. Please check logs." );
		}
	}
}

/**
 * Function to start global conainer if it is not running.
 *
 * @param string $container Global container to be brought up.
 */
function init_global_container( $service, $container = '' ) {

	if ( empty( $container ) ) {
		$container = 'ee-' . $service;
	}
	if ( ! EE::docker()::docker_network_exists( GLOBAL_BACKEND_NETWORK ) &&
	     ! EE::docker()::create_network( GLOBAL_BACKEND_NETWORK ) ) {
		EE::error( 'Unable to create network ' . GLOBAL_BACKEND_NETWORK );
	}
	if ( ! EE::docker()::docker_network_exists( GLOBAL_FRONTEND_NETWORK ) &&
	     ! EE::docker()::create_network( GLOBAL_FRONTEND_NETWORK ) ) {
		EE::error( 'Unable to create network ' . GLOBAL_FRONTEND_NETWORK );
	}

	$fs = new Filesystem();

	if ( ! $fs->exists( EE_ROOT_DIR . '/services/docker-compose.yml' ) ) {
		generate_global_docker_compose_yml( $fs );
	}

	if ( 'running' !== EE::docker()::container_status( $container ) ) {
		chdir( EE_ROOT_DIR . '/services' );

		if ( empty( EE::docker()::get_volumes_by_label( $service ) ) ) {
			create_global_volumes();
		}

		EE::docker()::boot_container( $container, 'docker-compose up -d ' . $service );
	} else {
		EE::log( "$service: Service already running" );

		return;
	}

	EE::success( "$container container is up" );

}

/**
 * Function to create all necessary volumes for global containers.
 */
function create_global_volumes() {

	$volumes = [
		[
			'name'            => 'certs',
			'path_to_symlink' => EE_ROOT_DIR . '/services/nginx-proxy/certs',
		],
		[
			'name'            => 'dhparam',
			'path_to_symlink' => EE_ROOT_DIR . '/services/nginx-proxy/dhparam',
		],
		[
			'name'            => 'confd',
			'path_to_symlink' => EE_ROOT_DIR . '/services/nginx-proxy/conf.d',
		],
		[
			'name'            => 'htpasswd',
			'path_to_symlink' => EE_ROOT_DIR . '/services/nginx-proxy/htpasswd',
		],
		[
			'name'            => 'vhostd',
			'path_to_symlink' => EE_ROOT_DIR . '/services/nginx-proxy/vhost.d',
		],
		[
			'name'            => 'html',
			'path_to_symlink' => EE_ROOT_DIR . '/services/nginx-proxy/html',
		],
	];

	$volumes_db    = [
		[
			'name'            => 'data_db',
			'path_to_symlink' => EE_ROOT_DIR . '/services/app/db',
		],
	];
	$volumes_redis = [
		[
			'name'            => 'data_redis',
			'path_to_symlink' => EE_ROOT_DIR . '/services/redis',
		],
	];

	if ( empty( EE::docker()::get_volumes_by_label( 'global-nginx-proxy' ) ) ) {
		EE::docker()::create_volumes( 'global-nginx-proxy', $volumes, false );
	}

	if ( empty( EE::docker()::get_volumes_by_label( GLOBAL_DB ) ) ) {
		EE::docker()::create_volumes( GLOBAL_DB, $volumes_db, false );
	}

	if ( empty( EE::docker()::get_volumes_by_label( GLOBAL_REDIS ) ) ) {
		EE::docker()::create_volumes( GLOBAL_REDIS, $volumes_redis, false );
	}
}

/**
 * Generates global docker-compose.yml at EE_ROOT_DIR
 *
 * @param Filesystem $fs Filesystem object to write file.
 */
function generate_global_docker_compose_yml( Filesystem $fs ) {

	$img_versions    = EE\Utils\get_image_versions();
	$config_80_port  = \EE\Utils\get_config_value( 'proxy_80_port', 80 );
	$config_443_port = \EE\Utils\get_config_value( 'proxy_443_port', 443 );

	$data = [
		'services'        => [
			[
				'name'           => 'global-nginx-proxy',
				'container_name' => EE_PROXY_TYPE,
				'image'          => 'easyengine/nginx-proxy:' . $img_versions['easyengine/nginx-proxy'],
				'restart'        => 'always',
				'ports'          => [
					"$config_80_port:80",
					"$config_443_port:443",
				],
				'environment'    => [
					'LOCAL_USER_ID=' . posix_geteuid(),
					'LOCAL_GROUP_ID=' . posix_getegid(),
				],
				'volumes'        => [
					'certs:/etc/nginx/certs',
					'dhparam:/etc/nginx/dhparam',
					'confd:/etc/nginx/conf.d',
					'htpasswd:/etc/nginx/htpasswd',
					'vhostd:/etc/nginx/vhost.d',
					'html:/usr/share/nginx/html',
					'/var/run/docker.sock:/tmp/docker.sock:ro',
				],
				'networks'       => [
					'global-frontend-network',
				],
			],
			[
				'name'           => GLOBAL_DB,
				'container_name' => GLOBAL_DB_CONTAINER,
				'image'          => 'easyengine/mariadb:' . $img_versions['easyengine/mariadb'],
				'restart'        => 'always',
				'environment'    => [
					'MYSQL_ROOT_PASSWORD=' . \EE\Utils\random_password(),
				],
				'volumes'        => [ 'data_db:/var/lib/mysql' ],
				'networks'       => [
					'global-backend-network',
				],
			],
			[
				'name'           => GLOBAL_REDIS,
				'container_name' => GLOBAL_REDIS_CONTAINER,
				'image'          => 'easyengine/redis:' . $img_versions['easyengine/redis'],
				'restart'        => 'always',
				'volumes'        => [ 'data_redis:/data' ],
				'networks'       => [
					'global-backend-network',
				],
			],
		],
		'created_volumes' => [
			'external_vols' => [
				[ 'prefix' => 'global-nginx-proxy', 'ext_vol_name' => 'certs' ],
				[ 'prefix' => 'global-nginx-proxy', 'ext_vol_name' => 'dhparam' ],
				[ 'prefix' => 'global-nginx-proxy', 'ext_vol_name' => 'confd' ],
				[ 'prefix' => 'global-nginx-proxy', 'ext_vol_name' => 'htpasswd' ],
				[ 'prefix' => 'global-nginx-proxy', 'ext_vol_name' => 'vhostd' ],
				[ 'prefix' => 'global-nginx-proxy', 'ext_vol_name' => 'html' ],
				[ 'prefix' => GLOBAL_DB, 'ext_vol_name' => 'data_db' ],
				[ 'prefix' => GLOBAL_REDIS, 'ext_vol_name' => 'data_redis' ],
			],
		],
	];

	$contents = EE\Utils\mustache_render( SERVICE_TEMPLATE_ROOT . '/global_docker_compose.yml.mustache', $data );
	$fs->dumpFile( EE_ROOT_DIR . '/services/docker-compose.yml', $contents );
}
