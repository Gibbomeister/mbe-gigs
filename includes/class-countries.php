<?php
/**
 * Country codes to names.
 *
 * GigPress stored a two-letter code per venue (plus its own "UK" for the United
 * Kingdom), and the importer carries that code across unchanged. This turns it back
 * into a name for display. Anything that isn't a known code — a name typed into the
 * venue screen — is shown as entered.
 *
 * @package MBE_Gigs
 */

defined( 'ABSPATH' ) || exit;

class MBE_Gigs_Countries {

	/**
	 * Codes that aren't ISO 3166 but turn up in GigPress data, mapped to the ISO code.
	 *
	 * @var array
	 */
	protected static $aliases = array(
		'UK' => 'GB',
	);

	/**
	 * The site's home country: gigs there show no country in lists, gigs anywhere else do.
	 *
	 * @return string Upper-case ISO code.
	 */
	public static function home() {
		return self::normalise( (string) apply_filters( 'mbe_gigs_home_country', 'AU' ) );
	}

	/**
	 * Upper-cased, trimmed, with aliases resolved — so "uk", "UK" and "GB" compare equal.
	 *
	 * @param string $value Code or name.
	 * @return string
	 */
	public static function normalise( $value ) {
		$value = strtoupper( trim( (string) $value ) );

		return isset( self::$aliases[ $value ] ) ? self::$aliases[ $value ] : $value;
	}

	/**
	 * Whether a venue's country is the site's home country. A blank country counts as
	 * home: the venue screen defaults to AU and an empty field means nobody said otherwise.
	 *
	 * @param string $value Code or name.
	 * @return bool
	 */
	public static function is_home( $value ) {
		$code = self::normalise( $value );

		return '' === $code || self::home() === $code || strtoupper( self::name( self::home() ) ) === $code;
	}

	/**
	 * Display name for a code; a value that isn't a known code comes back as entered.
	 *
	 * @param string $value Code or name.
	 * @return string
	 */
	public static function name( $value ) {
		$value = trim( (string) $value );
		$code  = self::normalise( $value );
		$list  = self::all();

		$name = isset( $list[ $code ] ) ? $list[ $code ] : $value;

		return (string) apply_filters( 'mbe_gigs_country_name', $name, $value );
	}

	/**
	 * ISO 3166-1 alpha-2 code => short English name.
	 *
	 * @return array
	 */
	public static function all() {
		return array(
			'AD' => 'Andorra',
			'AE' => 'United Arab Emirates',
			'AF' => 'Afghanistan',
			'AG' => 'Antigua and Barbuda',
			'AI' => 'Anguilla',
			'AL' => 'Albania',
			'AM' => 'Armenia',
			'AO' => 'Angola',
			'AQ' => 'Antarctica',
			'AR' => 'Argentina',
			'AS' => 'American Samoa',
			'AT' => 'Austria',
			'AU' => 'Australia',
			'AW' => 'Aruba',
			'AX' => 'Åland Islands',
			'AZ' => 'Azerbaijan',
			'BA' => 'Bosnia and Herzegovina',
			'BB' => 'Barbados',
			'BD' => 'Bangladesh',
			'BE' => 'Belgium',
			'BF' => 'Burkina Faso',
			'BG' => 'Bulgaria',
			'BH' => 'Bahrain',
			'BI' => 'Burundi',
			'BJ' => 'Benin',
			'BL' => 'Saint Barthélemy',
			'BM' => 'Bermuda',
			'BN' => 'Brunei Darussalam',
			'BO' => 'Bolivia',
			'BQ' => 'Bonaire, Sint Eustatius and Saba',
			'BR' => 'Brazil',
			'BS' => 'Bahamas',
			'BT' => 'Bhutan',
			'BV' => 'Bouvet Island',
			'BW' => 'Botswana',
			'BY' => 'Belarus',
			'BZ' => 'Belize',
			'CA' => 'Canada',
			'CC' => 'Cocos (Keeling) Islands',
			'CD' => 'Congo, The Democratic Republic of the',
			'CF' => 'Central African Republic',
			'CG' => 'Congo',
			'CH' => 'Switzerland',
			'CI' => 'Côte d\'Ivoire',
			'CK' => 'Cook Islands',
			'CL' => 'Chile',
			'CM' => 'Cameroon',
			'CN' => 'China',
			'CO' => 'Colombia',
			'CR' => 'Costa Rica',
			'CU' => 'Cuba',
			'CV' => 'Cabo Verde',
			'CW' => 'Curaçao',
			'CX' => 'Christmas Island',
			'CY' => 'Cyprus',
			'CZ' => 'Czechia',
			'DE' => 'Germany',
			'DJ' => 'Djibouti',
			'DK' => 'Denmark',
			'DM' => 'Dominica',
			'DO' => 'Dominican Republic',
			'DZ' => 'Algeria',
			'EC' => 'Ecuador',
			'EE' => 'Estonia',
			'EG' => 'Egypt',
			'EH' => 'Western Sahara',
			'ER' => 'Eritrea',
			'ES' => 'Spain',
			'ET' => 'Ethiopia',
			'FI' => 'Finland',
			'FJ' => 'Fiji',
			'FK' => 'Falkland Islands (Malvinas)',
			'FM' => 'Micronesia, Federated States of',
			'FO' => 'Faroe Islands',
			'FR' => 'France',
			'GA' => 'Gabon',
			'GB' => 'United Kingdom',
			'GD' => 'Grenada',
			'GE' => 'Georgia',
			'GF' => 'French Guiana',
			'GG' => 'Guernsey',
			'GH' => 'Ghana',
			'GI' => 'Gibraltar',
			'GL' => 'Greenland',
			'GM' => 'Gambia',
			'GN' => 'Guinea',
			'GP' => 'Guadeloupe',
			'GQ' => 'Equatorial Guinea',
			'GR' => 'Greece',
			'GS' => 'South Georgia and the South Sandwich Islands',
			'GT' => 'Guatemala',
			'GU' => 'Guam',
			'GW' => 'Guinea-Bissau',
			'GY' => 'Guyana',
			'HK' => 'Hong Kong',
			'HM' => 'Heard Island and McDonald Islands',
			'HN' => 'Honduras',
			'HR' => 'Croatia',
			'HT' => 'Haiti',
			'HU' => 'Hungary',
			'ID' => 'Indonesia',
			'IE' => 'Ireland',
			'IL' => 'Israel',
			'IM' => 'Isle of Man',
			'IN' => 'India',
			'IO' => 'British Indian Ocean Territory',
			'IQ' => 'Iraq',
			'IR' => 'Iran',
			'IS' => 'Iceland',
			'IT' => 'Italy',
			'JE' => 'Jersey',
			'JM' => 'Jamaica',
			'JO' => 'Jordan',
			'JP' => 'Japan',
			'KE' => 'Kenya',
			'KG' => 'Kyrgyzstan',
			'KH' => 'Cambodia',
			'KI' => 'Kiribati',
			'KM' => 'Comoros',
			'KN' => 'Saint Kitts and Nevis',
			'KP' => 'North Korea',
			'KR' => 'South Korea',
			'KW' => 'Kuwait',
			'KY' => 'Cayman Islands',
			'KZ' => 'Kazakhstan',
			'LA' => 'Laos',
			'LB' => 'Lebanon',
			'LC' => 'Saint Lucia',
			'LI' => 'Liechtenstein',
			'LK' => 'Sri Lanka',
			'LR' => 'Liberia',
			'LS' => 'Lesotho',
			'LT' => 'Lithuania',
			'LU' => 'Luxembourg',
			'LV' => 'Latvia',
			'LY' => 'Libya',
			'MA' => 'Morocco',
			'MC' => 'Monaco',
			'MD' => 'Moldova',
			'ME' => 'Montenegro',
			'MF' => 'Saint Martin (French part)',
			'MG' => 'Madagascar',
			'MH' => 'Marshall Islands',
			'MK' => 'North Macedonia',
			'ML' => 'Mali',
			'MM' => 'Myanmar',
			'MN' => 'Mongolia',
			'MO' => 'Macao',
			'MP' => 'Northern Mariana Islands',
			'MQ' => 'Martinique',
			'MR' => 'Mauritania',
			'MS' => 'Montserrat',
			'MT' => 'Malta',
			'MU' => 'Mauritius',
			'MV' => 'Maldives',
			'MW' => 'Malawi',
			'MX' => 'Mexico',
			'MY' => 'Malaysia',
			'MZ' => 'Mozambique',
			'NA' => 'Namibia',
			'NC' => 'New Caledonia',
			'NE' => 'Niger',
			'NF' => 'Norfolk Island',
			'NG' => 'Nigeria',
			'NI' => 'Nicaragua',
			'NL' => 'Netherlands',
			'NO' => 'Norway',
			'NP' => 'Nepal',
			'NR' => 'Nauru',
			'NU' => 'Niue',
			'NZ' => 'New Zealand',
			'OM' => 'Oman',
			'PA' => 'Panama',
			'PE' => 'Peru',
			'PF' => 'French Polynesia',
			'PG' => 'Papua New Guinea',
			'PH' => 'Philippines',
			'PK' => 'Pakistan',
			'PL' => 'Poland',
			'PM' => 'Saint Pierre and Miquelon',
			'PN' => 'Pitcairn',
			'PR' => 'Puerto Rico',
			'PS' => 'Palestine, State of',
			'PT' => 'Portugal',
			'PW' => 'Palau',
			'PY' => 'Paraguay',
			'QA' => 'Qatar',
			'RE' => 'Réunion',
			'RO' => 'Romania',
			'RS' => 'Serbia',
			'RU' => 'Russian Federation',
			'RW' => 'Rwanda',
			'SA' => 'Saudi Arabia',
			'SB' => 'Solomon Islands',
			'SC' => 'Seychelles',
			'SD' => 'Sudan',
			'SE' => 'Sweden',
			'SG' => 'Singapore',
			'SH' => 'Saint Helena, Ascension and Tristan da Cunha',
			'SI' => 'Slovenia',
			'SJ' => 'Svalbard and Jan Mayen',
			'SK' => 'Slovakia',
			'SL' => 'Sierra Leone',
			'SM' => 'San Marino',
			'SN' => 'Senegal',
			'SO' => 'Somalia',
			'SR' => 'Suriname',
			'SS' => 'South Sudan',
			'ST' => 'Sao Tome and Principe',
			'SV' => 'El Salvador',
			'SX' => 'Sint Maarten (Dutch part)',
			'SY' => 'Syria',
			'SZ' => 'Eswatini',
			'TC' => 'Turks and Caicos Islands',
			'TD' => 'Chad',
			'TF' => 'French Southern Territories',
			'TG' => 'Togo',
			'TH' => 'Thailand',
			'TJ' => 'Tajikistan',
			'TK' => 'Tokelau',
			'TL' => 'Timor-Leste',
			'TM' => 'Turkmenistan',
			'TN' => 'Tunisia',
			'TO' => 'Tonga',
			'TR' => 'Türkiye',
			'TT' => 'Trinidad and Tobago',
			'TV' => 'Tuvalu',
			'TW' => 'Taiwan',
			'TZ' => 'Tanzania',
			'UA' => 'Ukraine',
			'UG' => 'Uganda',
			'UM' => 'United States Minor Outlying Islands',
			'US' => 'United States',
			'UY' => 'Uruguay',
			'UZ' => 'Uzbekistan',
			'VA' => 'Holy See (Vatican City State)',
			'VC' => 'Saint Vincent and the Grenadines',
			'VE' => 'Venezuela',
			'VG' => 'Virgin Islands, British',
			'VI' => 'Virgin Islands, U.S.',
			'VN' => 'Vietnam',
			'VU' => 'Vanuatu',
			'WF' => 'Wallis and Futuna',
			'WS' => 'Samoa',
			'YE' => 'Yemen',
			'YT' => 'Mayotte',
			'ZA' => 'South Africa',
			'ZM' => 'Zambia',
			'ZW' => 'Zimbabwe',
		);
	}
}
