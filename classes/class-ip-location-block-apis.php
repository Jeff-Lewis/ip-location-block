<?php
/**
 * IP Location Block - IP Address Geolocation API Class
 *
 * @package   IP_Location_Block
 * @author    Darko Gjorgjijoski <dg@darkog.com>
 * @license   GPL-3.0
 * @link      https://iplocationblock.com/
 * @copyright 2021 darkog
 * @copyright 2013-2019 tokkonopapa
 */

/**
 * Service type
 *
 */
define( 'IP_LOCATION_BLOCK_API_TYPE_IPV4', 1 ); // can handle IPv4
define( 'IP_LOCATION_BLOCK_API_TYPE_IPV6', 2 ); // can handle IPv6
define( 'IP_LOCATION_BLOCK_API_TYPE_BOTH', 3 ); // can handle both IPv4 and IPv6

/**
 * Class IP_Location_Block_API
 * Base api class
 */
abstract class IP_Location_Block_API {

	/**
	 * These values must be instantiated in child class
	 *
	 *//*
	protected $template = array(
		'type' => IP_LOCATION_BLOCK_API_TYPE_[IPV4 | IPV6 | BOTH],
		'url' => 'http://example.com/%API_KEY%/%API_FORMAT%/%API_OPTION%/%API_IP%';
		'api' => array(
			'%API_IP%'     => '', // should be set in build_url()
			'%API_KEY%'    => '', // should be set in __construct()
			'%API_FORMAT%' => '', // may be set in child class
			'%API_OPTION%' => '', // may be set in child class
		),
		'transform' => array(
			'errorMessage' => '',
			'countryCode'  => '',
			'countryName'  => '',
			'regionName'   => '',
			'cityName'     => '',
			'latitude'     => '',
			'longitude'    => '',
		)
	);*/

	/**
	 * Constructer & Destructer
	 *
	 * @param null $api_key
	 */
	protected function __construct( $api_key = null ) {
		if ( is_string( $api_key ) ) {
			$this->template['api']['%API_KEY%'] = $api_key;
		}
	}

	/**
	 * Build URL from template
	 *
	 * @param $ip
	 * @param $template
	 *
	 * @return string|string[]
	 */
	protected static function build_url( $ip, $template ) {
		$template['api']['%API_IP%'] = $ip;

		return str_replace(
			array_keys( $template['api'] ),
			array_values( $template['api'] ),
			$template['url']
		);
	}

	/**
	 * Fetch service provider to get geolocation information
	 *
	 * @param $ip
	 * @param $args
	 * @param $template
	 *
	 * @return array|false|string[]
	 */
	protected static function fetch_provider( $ip, $args, $template ) {

		// check supported type of IP address
		if ( ! ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && ( $template['type'] & IP_LOCATION_BLOCK_API_TYPE_IPV4 ) ) &&
		     ! ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) && ( $template['type'] & IP_LOCATION_BLOCK_API_TYPE_IPV6 ) ) ) {
			return false;
		}

		// build query
		$tmp = self::build_url( $ip, $template );
		// https://codex.wordpress.org/Function_Reference/wp_remote_get
		$res = wp_remote_get( $tmp, $args ); // @since  2.7.0

		if ( is_wp_error( $res ) ) {
			return array( 'errorMessage' => $res->get_error_message() );
		}
		$tmp = wp_remote_retrieve_header( $res, 'content-type' );
		$res = wp_remote_retrieve_body( $res );

		// clear decoded data
		$data = array();

		// extract content type
		// ex: "Content-type: text/plain; charset=utf-8"
		if ( $tmp ) {
			$tmp = explode( '/', $tmp, 2 );
			$tmp = explode( ';', $tmp[1], 2 );
			$tmp = trim( $tmp[0] );
		}

		switch ( $tmp ) {
			// decode json
			case 'json':
			case 'html':  // ipinfo.io, Xhanch
			case 'plain': // geoPlugin
				$data = json_decode( $res, true ); // PHP 5 >= 5.2.0, PECL json >= 1.2.0
				if ( null === $data ) // ipinfo.io (get_country)
				{
					$data[ $template['transform']['countryCode'] ] = trim( $res );
				}
				break;

			// decode xml
			case 'xml':
				$tmp = '/\<(.+?)\>(?:\<\!\[CDATA\[)?([^\>]*?)(?:\]\]\>)?\<\/\\1\>/i';
				if ( preg_match_all( $tmp, $res, $matches ) !== false ) {
					if ( is_array( $matches[1] ) && ! empty( $matches[1] ) ) {
						foreach ( $matches[1] as $key => $val ) {
							$data[ $val ] = $matches[2][ $key ];
						}
					}
				}
				break;

			// unknown format
			default:
				return array( 'errorMessage' => "unsupported content type: $tmp" );
		}

		// transformation
		$res = array();
		foreach ( $template['transform'] as $key => $val ) {
			if ( ! empty( $val ) && ! empty( $data[ $val ] ) ) {
				$res[ $key ] = is_string( $data[ $val ] ) ? esc_html( $data[ $val ] ) : $data[ $val ];
			}
		}

		// if country code is '-' or 'UNDEFINED' then error.
		if ( isset( $res['countryCode'] ) && is_string( $res['countryCode'] ) ) {
			$res['countryCode'] = preg_match( '/^[A-Z]{2}/', $res['countryCode'], $matches ) ? $matches[0] : null;
		}

		return $res;
	}

	/**
	 * Get geolocation information from service provider
	 *
	 * @param $ip
	 * @param array $args
	 *
	 * @return array|false|string[]
	 */
	public function get_location( $ip, $args = array() ) {
		return self::fetch_provider( $ip, $args, $this->template );
	}

	/**
	 * Get only country code
	 *
	 * Override this method if a provider supports this feature for quick response.
	 *
	 * @param $ip
	 * @param array $args
	 *
	 * @return false|mixed|string|null
	 */
	public function get_country( $ip, $args = array() ) {
		$res = $this->get_location( $ip, $args );

		return false === $res ? false : ( empty( $res['countryCode'] ) ? null : $res['countryCode'] );
	}

	/**
	 * Convert provider name to class name
	 *
	 * @param $provider
	 *
	 * @return string|null
	 */
	public static function get_class_name( $provider ) {
		$provider = 'IP_Location_Block_API_' . preg_replace( '/[\W]/', '', $provider );

		return class_exists( $provider, false ) ? $provider : null;
	}

	/**
	 * Get option key
	 *
	 * @param $provider
	 * @param $options
	 *
	 * @return mixed|null
	 */
	public static function get_api_key( $provider, $options ) {
		$providers = array();
		if ( ! empty( $options['providers'] ) ) {
			$providers = array_change_key_case( $options['providers'], CASE_LOWER );
		}
		$provider = strtolower( $provider );

		return empty( $providers[ $provider ] ) ? null : $providers[ $provider ];
	}

	/**
	 * Instance of inherited object
	 * @var static[]
	 */
	private static $instance = array();

	/**
	 * @param $provider
	 * @param $options
	 *
	 * @return static|null
	 */
	public static function get_instance( $provider, $options ) {
		if ( $name = self::get_class_name( $provider ) ) {
			if ( empty( self::$instance[ $name ] ) ) {
				return self::$instance[ $name ] = new $name( self::get_api_key( $provider, $options ) );
			} else {
				return self::$instance[ $name ];
			}
		}

		return null;
	}
}

/**
 * Class for IP-API.com
 *
 * URL         : http://ip-api.com/
 * Term of use : http://ip-api.com/docs/#usage_limits
 * Licence fee : free for non-commercial use
 * Rate limit  : 240 requests per minute
 * Sample URL  : http://ip-api.com/json/2a00:1210:fffe:200::1
 * Sample URL  : http://ip-api.com/xml/yahoo.co.jp
 * Input type  : IP address (IPv4, IPv6 with limited coverage) / domain name
 * Output type : json, xml
 */
class IP_Location_Block_API_IPAPIcom extends IP_Location_Block_API {
	protected $template = array(
		'type'      => IP_LOCATION_BLOCK_API_TYPE_BOTH,
		'url'       => 'http://ip-api.com/%API_FORMAT%/%API_IP%',
		'api'       => array(
			'%API_FORMAT%' => 'json',
		),
		'transform' => array(
			'errorMessage' => 'error',
			'countryCode'  => 'countryCode',
			'countryName'  => 'country',
			'regionName'   => 'regionName',
			'cityName'     => 'city',
			'latitude'     => 'lat',
			'longitude'    => 'lon',
		)
	);
}

/**
 * Class for GeoIPLookup.net
 *
 * URL         : http://geoiplookup.net/
 * Term of use : http://geoiplookup.net/terms-of-use.php
 * Licence fee : free
 * Rate limit  : none
 * Sample URL  : http://api.geoiplookup.net/?query=2a00:1210:fffe:200::1
 * Input type  : IP address (IPv4, IPv6)
 * Output type : xml
 */
class IP_Location_Block_API_GeoIPLookup extends IP_Location_Block_API {
	protected $template = array(
		'type'      => IP_LOCATION_BLOCK_API_TYPE_BOTH,
		'url'       => 'http://api.geoiplookup.net/?query=%API_IP%',
		'api'       => array(),
		'transform' => array(
			'countryCode' => 'countrycode',
			'countryName' => 'countryname',
			'regionName'  => 'countryname',
			'cityName'    => 'city',
			'latitude'    => 'latitude',
			'longitude'   => 'longitude',
		)
	);
}

/**
 * Class for ipinfo.io
 *
 * URL         : https://ipinfo.io/
 * Term of use : https://ipinfo.io/developers#terms
 * Licence fee : free
 * Rate limit  : 1,000 lookups daily
 * Sample URL  : https://ipinfo.io/124.83.187.140/json
 * Sample URL  : https://ipinfo.io/124.83.187.140/country
 * Input type  : IP address (IPv4)
 * Output type : json
 */
class IP_Location_Block_API_ipinfoio extends IP_Location_Block_API {

	protected $template = array(
		'type'      => IP_LOCATION_BLOCK_API_TYPE_BOTH,
		'url'       => 'https://ipinfo.io/%API_IP%?token=%API_KEY%',
		'api'       => array(),
		'transform' => array(
			'countryCode' => 'country',
			'countryName' => 'country',
			'regionName'  => 'region',
			'cityName'    => 'city',
			'latitude'    => 'loc',
			'longitude'   => 'loc',
		)
	);

	/**
	 * Returns the location
	 *
	 * @param $ip
	 * @param array $args
	 *
	 * @return array|false|string[]
	 */
	public function get_location( $ip, $args = array() ) {
		$res = parent::get_location( $ip, $args );
		if ( ! empty( $res ) && ! empty( $res['latitude'] ) ) {
			$loc              = explode( ',', $res['latitude'] );
			$res['latitude']  = $loc[0];
			$res['longitude'] = $loc[1];
		}

		return $res;
	}

	/**
	 * Returns the country
	 *
	 * @param $ip
	 * @param array $args
	 *
	 * @return false|mixed|string|null
	 */
	public function get_country( $ip, $args = array() ) {
		$this->template['api']['%API_FORMAT%'] = '';
		$this->template['api']['%API_OPTION%'] = 'country';

		return parent::get_country( $ip, $args );
	}
}

/**
 * Class for ipapi
 *
 * URL         : https://ipapi.com/
 * Term of use : https://ipapi.com/terms
 * Licence fee : free to use the API
 * Rate limit  : 10,000 reqests per month
 * Sample URL  : http://api.ipapi.com/2a00:1210:fffe:200::1?access_key=...
 * Input type  : IP address (IPv4, IPv6)
 * Output type : json
 */
class IP_Location_Block_API_ipapi extends IP_Location_Block_API {

	protected $template = array(
		'type'      => IP_LOCATION_BLOCK_API_TYPE_BOTH,
		'url'       => 'http://api.ipapi.com/%API_IP%?access_key=%API_KEY%',
		'api'       => array(),
		'transform' => array(
			'countryCode' => 'country_code',
			'countryName' => 'country_name',
			'cityName'    => 'city',
			'latitude'    => 'latitude',
			'longitude'   => 'longitude',
			'error'       => 'error',
		)
	);

	/**
	 * Returns the location
	 *
	 * @param $ip
	 * @param array $args
	 *
	 * @return array|false|string[]
	 */
	public function get_location( $ip, $args = array() ) {
		$res = parent::get_location( $ip, $args );
		if ( isset( $res['countryName'] ) ) {
			$res['countryCode'] = esc_html( $res['countryCode'] );
			$res['countryName'] = esc_html( $res['countryName'] );
			$res['latitude']    = esc_html( $res['latitude'] );
			$res['longitude']   = esc_html( $res['longitude'] );

			return $res;
		} else {
			return array( 'errorMessage' => esc_html( $res['error']['info'] ) );
		}
	}
}

/**
 * Class for Ipdata.co
 *
 * URL         : https://ipdata.co/
 * Term of use : https://ipdata.co/terms.html
 * Licence fee : free
 * Rate limit  : 1,500 lookups free daily
 * Sample URL  : https://api.ipdata.co/8.8.8.8?api-key=...
 * Input type  : IP address (IPv4, IPv6)
 * Output type : json
 */
class IP_Location_Block_API_Ipdataco extends IP_Location_Block_API {
	protected $template = array(
		'type'      => IP_LOCATION_BLOCK_API_TYPE_BOTH,
		'url'       => 'https://api.ipdata.co/%API_IP%?api-key=%API_KEY%',
		'api'       => array(),
		'transform' => array(
			'countryCode' => 'country_code',
			'countryName' => 'country_name',
			'regionName'  => 'region',
			'cityName'    => 'city',
			'latitude'    => 'latitude',
			'longitude'   => 'longitude',
		)
	);
}

/**
 * Class for ipstack
 *
 * URL         : https://ipstack.com/
 * Term of use : https://ipstack.com/terms
 * Licence fee : free for registered user
 * Rate limit  : 10,000 queries per month for free (https can be available for premium users)
 * Sample URL  : http://api.ipstack.com/186.116.207.169?access_key=YOUR_ACCESS_KEY&output=json&legacy=1
 * Input type  : IP address (IPv4, IPv6) / domain name
 * Output type : json, xml
 */
class IP_Location_Block_API_ipstack extends IP_Location_Block_API {
	protected $template = array(
		'type'      => IP_LOCATION_BLOCK_API_TYPE_BOTH,
		'url'       => 'http://api.ipstack.com/%API_IP%?access_key=%API_KEY%&output=%API_FORMAT%',
		'api'       => array(
			'%API_FORMAT%' => 'json',
		),
		'transform' => array(
			'countryCode' => 'country_code',
			'countryName' => 'country_name',
			'regionName'  => 'region_name',
			'cityName'    => 'city',
			'latitude'    => 'latitude',
			'longitude'   => 'longitude',
		)
	);
}

/**
 * Class for IPInfoDB
 *
 * URL         : https://ipinfodb.com/
 * Term of use :
 * Licence fee : free (need to regist to get API key)
 * Rate limit  : 2 queries/second for registered user
 * Sample URL  : https://api.ipinfodb.com/v3/ip-city/?key=...&format=xml&ip=124.83.187.140
 * Sample URL  : https://api.ipinfodb.com/v3/ip-country/?key=...&format=xml&ip=yahoo.co.jp
 * Input type  : IP address (IPv4, IPv6) / domain name
 * Output type : json, xml
 */
class IP_Location_Block_API_IPInfoDB extends IP_Location_Block_API {

	/**
	 * The template
	 * @var array
	 */
	protected $template = array(
		'type'      => IP_LOCATION_BLOCK_API_TYPE_BOTH,
		'url'       => 'https://api.ipinfodb.com/v3/%API_OPTION%/?key=%API_KEY%&format=%API_FORMAT%&ip=%API_IP%',
		'api'       => array(
			'%API_FORMAT%' => 'xml',
			'%API_OPTION%' => 'ip-city',
		),
		'transform' => array(
			'countryCode' => 'countryCode',
			'countryName' => 'countryName',
			'regionName'  => 'regionName',
			'cityName'    => 'cityName',
			'latitude'    => 'latitude',
			'longitude'   => 'longitude',
		)
	);

	/**
	 * IP_Location_Block_API_IPInfoDB constructor.
	 *
	 * @param null $api_key
	 */
	public function __construct( $api_key = null ) {
		// sanitization
		parent::__construct( preg_replace( '/\W/', '', $api_key ) );
	}

	/**
	 * Returns the country
	 *
	 * @param $ip
	 * @param array $args
	 *
	 * @return false|mixed|string|null
	 */
	public function get_country( $ip, $args = array() ) {
		$this->template['api']['%API_OPTION%'] = 'ip-country';

		return parent::get_country( $ip, $args );
	}
}

/**
 * Class for Cache
 *
 * Input type  : IP address (IPv4, IPv6)
 * Output type : array
 */
class IP_Location_Block_API_Cache extends IP_Location_Block_API {

	/**
	 * Memory cache
	 * @var array
	 */
	protected static $memcache = array();

	/**
	 * Update c ache
	 *
	 * @param $hook
	 * @param $validate
	 * @param $settings
	 * @param bool $countup
	 *
	 * @return array
	 */
	public static function update_cache( $hook, $validate, $settings, $countup = true ) {
		$time  = $_SERVER['REQUEST_TIME'];
		$cache = self::get_cache( $ip = $validate['ip'], $settings['cache_hold'] );

		if ( $cache ) {
			$fail = isset( $validate['fail'] ) ? $validate['fail'] : 0;
			$call = $cache['reqs'] + ( $countup ? 1 : 0 ); // prevent duplicate count up
			$last = $cache['last'];
			$view = $cache['view'];
		} else { // if new cache then reset these values
			$fail = 0;
			$call = 1;
			$last = $time;
			$view = 1;
		}

		if ( $cache && 'public' === $hook ) {
			if ( $time - $last > $settings['behavior']['time'] ) {
				$view = 1;
			} else {
				++ $view;
			}
			$last = $time;
		}

		$cache = array(
			'time' => $time,
			'ip'   => $ip,
			'hook' => $hook,
			'asn'  => $validate['asn'], // @since 3.0.4
			'code' => $validate['code'],
			'auth' => $validate['auth'], // get_current_user_id() > 0
			'fail' => $fail, // $validate['auth'] ? 0 : $fail,
			'reqs' => $settings['save_statistics'] ? $call : 0,
			'last' => $last,
			'view' => $view,
			'host' => isset( $validate['host'] ) && $validate['host'] !== $ip ? $validate['host'] : '',
		);

		// do not update cache while installing geolocation databases
		if ( $settings['cache_hold'] && ! ( $validate['auth'] && 'ZZ' === $validate['code'] ) ) {
			IP_Location_Block_Logs::update_cache( $cache );
		}

		return self::$memcache[ $ip ] = $cache;
	}

	/**
	 * Clear cache
	 */
	public static function clear_cache() {
		IP_Location_Block_Logs::clear_cache();
		self::$memcache = array();
	}

	/**
	 * Return the cache
	 *
	 * @param $ip
	 * @param bool $use_cache
	 *
	 * @return array|mixed|null
	 */
	public static function get_cache( $ip, $use_cache = true ) {
		if ( isset( self::$memcache[ $ip ] ) ) {
			return self::$memcache[ $ip ];
		} else {
			return $use_cache ? self::$memcache[ $ip ] = IP_Location_Block_Logs::search_cache( $ip ) : null;
		}
	}

	/**
	 * Return the location
	 *
	 * @param $ip
	 * @param array $args
	 *
	 * @return array|string[]
	 */
	public function get_location( $ip, $args = array() ) {
		if ( $cache = self::get_cache( $ip ) ) {
			return array( 'countryCode' => $cache['code'] );
		} else {
			return array( 'errorMessage' => 'not in the cache' );
		}
	}

	/**
	 * Returns the country
	 *
	 * @param $ip
	 * @param array $args
	 *
	 * @return array|false|mixed|string|null
	 */
	public function get_country( $ip, $args = array() ) {
		return ( $cache = self::get_cache( $ip ) ) ? ( isset( $args['cache'] ) ? $cache : $cache['code'] ) : null;
	}
}

/**
 * Provider support class
 *
 */
class IP_Location_Block_Provider {

	protected static $providers = array(
		'IP-API.com' => array(
			'key'  => null,
			'type' => 'IPv4, IPv6 / free for non-commercial use',
			'link' => '<a rel="noreferrer" href="http://ip-api.com/" title="IP-API.com - Free Geolocation API">http://ip-api.com/</a>&nbsp;(IPv4, IPv6 / free for non-commercial use)',
		),

		'GeoIPLookup' => array(
			'key'  => null,
			'type' => 'IPv4, IPv6 / free',
			'link' => '<a rel="noreferrer" href="http://geoiplookup.net/" title="What Is My IP Address | GeoIP Lookup">GeoIPLookup.net</a>&nbsp;(IPv4, IPv6 / free)',
		),

		'ipinfo.io' => array(
			'key'  => '',
			'type' => 'IPv4, IPv6 / free',
			'link' => '<a rel="noreferrer" href="https://ipinfo.io/" title="IP Address API and Data Solutions">https://ipinfo.io/</a>&nbsp;(IPv4, IPv6 / free up to 1,000 lookups daily)',
		),

		'ipapi' => array(
			'key'  => '',
			'type' => 'IPv4, IPv6 / free',
			'link' => '<a rel="noreferrer" href="https://ipapi.com/" title="ipapi - IP Address Lookup and Geolocation API">https://ipapi.com/</a>&nbsp;(IPv4, IPv6 / free up to 10,000 lookups monthly for registered user)',
		),

		'ipstack' => array(
			'key'  => '',
			'type' => 'IPv4, IPv6 / free for registered user',
			'link' => '<a rel="noreferrer" href="https://ipstack.com/" title="ipstack - Free IP Geolocation API">https://ipstack.com/</a>&nbsp;(IPv4, IPv6 / free for registered user)',
		),

		'IPInfoDB' => array(
			'key'  => '',
			'type' => 'IPv4, IPv6 / free for registered user',
			'link' => '<a rel="noreferrer" href="https://ipinfodb.com/" title="Free IP Geolocation Tools and API| IPInfoDB">https://ipinfodb.com/</a>&nbsp;(IPv4, IPv6 / free for registered user)',
		),
	);

	// Internal DB
	protected static $internals = array(
		'Cache' => array(
			'key'  => null,
			'type' => 'IPv4, IPv6',
			'link' => null,
		),
	);

	/**
	 * Register and get addon provider class information
	 *
	 * @param $api
	 */
	public static function register_addon( $api ) {
		self::$internals += $api;
	}

	/**
	 * Return addon providers
	 *
	 * @param array $providers
	 * @param false $force
	 *
	 * @return array
	 */
	public static function get_addons( $providers = array(), $force = false ) {
		$apis = array();

		foreach ( self::$internals as $key => $val ) {
			if ( 'Cache' !== $key && ( $force || ! isset( $providers[ $key ] ) || ! empty( $providers[ $key ] ) ) ) {
				$apis[] = $key;
			}
		}

		return $apis;
	}

	/**
	 * Returns the pairs of provider name and API key
	 *
	 * @param string $key
	 * @param bool $rand
	 * @param bool $cache
	 * @param bool $all
	 *
	 * @return array
	 */
	public static function get_providers( $key = 'key', $rand = false, $cache = false, $all = true ) {
		// add internal DB
		$list = array();
		foreach ( self::$internals as $provider => $tmp ) {
			if ( 'Cache' !== $provider || $cache ) {
				$list[ $provider ] = $tmp[ $key ];
			}
		}

		if ( $all ) {
			$tmp = array_keys( self::$providers );

			// randomize
			if ( $rand ) {
				shuffle( $tmp );
			}

			foreach ( $tmp as $name ) {
				$list[ $name ] = self::$providers[ $name ][ $key ];
			}
		}

		return $list;
	}

	/**
	 * Returns providers name list which are checked in settings
	 *
	 * @param $settings
	 * @param bool $rand
	 * @param bool $cache
	 * @param bool $all
	 *
	 * @return array
	 */
	public static function get_valid_providers( $settings, $rand = true, $cache = true, $all = false ) {
		$list      = array();
		$providers = $settings['providers']; // list of not selected and selected with api key
		$cache     &= $settings['cache_hold']; // exclude `Cache` when `IP address cache` is disabled

		foreach ( self::get_providers( 'key', $rand, $cache, empty( $settings['restrict_api'] ) || $all ) as $name => $key ) {
			// ( if $name has api key )         || ( if $name that does not need api key is selected )
			if ( ! empty( $providers[ $name ] ) || ( ! isset( $providers[ $name ] ) && null === $key ) ) {
				$list[] = $name;
			}
		}

		return $list;
	}

}

/**
 * Load additional plugins
 * @url https://iplocationblock.com/cloudflare-cloudfront-api-class-library/
 */
if ( class_exists( 'IP_Location_Block', false ) ) {
	// Avoid "The plugin does not have a valid header" on activation under WP4.0
	if ( is_plugin_active( IP_LOCATION_BLOCK_BASE ) ) {
		$dir = IP_Location_Block_Util::slashit(
			apply_filters( IP_Location_Block::PLUGIN_NAME . '-api-dir', IP_Location_Block_Util::get_storage_dir( 'apis' ) )
		);
		$plugins = ( is_dir( $dir ) ? scandir( $dir, defined( 'SCANDIR_SORT_DESCENDING' ) ? SCANDIR_SORT_DESCENDING : 1 ) : false );
		if ( false !== $plugins ) {
			$exclude = array( '.', '..' );
			foreach ( $plugins as $plugin ) {
				if ( ! in_array( $plugin, $exclude, true ) && is_dir( $dir . $plugin ) ) {
					$plugin_path = sprintf( '%s%s%sclass-%s.php', $dir, $plugin, DIRECTORY_SEPARATOR, $plugin );
					if ( file_exists( $plugin_path ) ) {
						require_once $plugin_path;
					}
				}
			}
		}
	}
}