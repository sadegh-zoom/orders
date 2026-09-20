<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'zoombaza_mZNgjbm' );

/** Database username */
define( 'DB_USER', 'zoombaza_tHspwuW' );

/** Database password */
define( 'DB_PASSWORD', 'yrpzhgK38W9vjEM5' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         '|UA4>rbPe&z-=T2|J:5xSgx3@R:jp7{g+Q(t*KTC{5ocJ%Oue)mfXJRMR/B<g*NU' );
define( 'SECURE_AUTH_KEY',  '5Dc4Dnq;wTlVv<Sf;~I7t,VmUUiDGK&[8TZWgaJYhj7%y+_B6VnErFZImW3w&3Ue' );
define( 'LOGGED_IN_KEY',    '_SGnrGj:2M2*n63xd`TZY[3Ia+w2*3?@c/IZg&LDSM{3AGnj-<:jU(J{be_Nz*r{' );
define( 'NONCE_KEY',        'tf%2&Q4RJQLWcF!KffLo~p]ela{(iYX,6Vb/0DU.YXSDrT#UAAl^it&F/cXrzG]+' );
define( 'AUTH_SALT',        '>;abz<iLO{-djFI#eYxgA8t6D5[b1^Zi@K#ALX}9@:t@_e:mc&}qrsupQ#ky~%.c' );
define( 'SECURE_AUTH_SALT', 'NJr8u|M7!>Jrq@p7[T4JOuQ@:C2:]A9rO4W3COS]?zwyFif>(69;f?v!%~-4NR6A' );
define( 'LOGGED_IN_SALT',   '3wbuhN1hj+2lEY~MK!V(+P#CIJe2b/y1IH!k^h8h_Xkuwy=ui2BfzZTG)h6E@7jw' );
define( 'NONCE_SALT',       'Bv1J]E4`R%pv0orUFSm(n(EL2kP]1fFnzWJ-_Z/Q6aDUDMST{0lgP2`NBo@873V9' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = '35pf_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */

define( 'DISABLE_WP_CRON', true );

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
