<?php
/**
 * Venue and artist page. See includes/class-term-page.php.
 *
 * The theme's header and footer, with the gig lists in between. On the Beaver Builder
 * theme the content sits in the theme's own container classes so it lines up with
 * every other page; elsewhere it gets a plain centred wrapper.
 *
 * @package MBE_Gigs
 */

defined( 'ABSPATH' ) || exit;

$mbe_term     = MBE_Gigs_Term_Page::current_term();
$mbe_is_venue = ( MBE_GIGS_TAX_VENUE === $mbe_term->taxonomy );
$mbe_upcoming = MBE_Gigs_Term_Page::dates( $mbe_term, 'upcoming' );
$mbe_past     = MBE_Gigs_Term_Page::dates( $mbe_term, 'past' );
$mbe_summary  = MBE_Gigs_Term_Page::summary( $mbe_term, $mbe_past );
$mbe_bb_theme = ( 'bb-theme' === get_template() );

get_header();

if ( $mbe_bb_theme ) {
	echo '<div class="fl-archive container"><div class="row"><div class="fl-content col-md-12">';
}
?>
<main class="mbe-gigs-term mbe-gigs-term--<?php echo $mbe_is_venue ? 'venue' : 'artist'; ?><?php echo $mbe_bb_theme ? '' : ' mbe-gigs-term--wrapped'; ?>">

	<header class="mbe-gigs-term__header">
		<h1 class="mbe-gigs-term__title"><?php echo esc_html( $mbe_term->name ); ?></h1>
		<?php
		if ( $mbe_is_venue ) {
			echo MBE_Gigs_Term_Page::venue_details( $mbe_term ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the method.
		}

		if ( '' !== trim( $mbe_term->description ) ) {
			echo '<div class="mbe-gigs-term__description">' . wp_kses_post( wpautop( $mbe_term->description ) ) . '</div>';
		}

		if ( '' !== $mbe_summary ) {
			printf( '<p class="mbe-gigs-term__summary">%s</p>', esc_html( $mbe_summary ) );
		}
		?>
	</header>

	<?php if ( $mbe_upcoming ) : ?>
		<section class="mbe-gigs-term__section mbe-gigs-term__section--upcoming">
			<h2 class="mbe-gigs-term__heading"><?php esc_html_e( 'Upcoming', 'mbe-gigs' ); ?></h2>
			<?php echo MBE_Gigs_Shortcode::render( MBE_Gigs_Term_Page::list_atts( $mbe_term, 'upcoming' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</section>
	<?php endif; ?>

	<?php if ( $mbe_past ) : ?>
		<section class="mbe-gigs-term__section mbe-gigs-term__section--past">
			<h2 class="mbe-gigs-term__heading"><?php esc_html_e( 'Past gigs', 'mbe-gigs' ); ?></h2>
			<?php echo MBE_Gigs_Shortcode::render( MBE_Gigs_Term_Page::list_atts( $mbe_term, 'past' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</section>
	<?php endif; ?>

	<?php if ( ! $mbe_upcoming && ! $mbe_past ) : ?>
		<p class="mbe-gigs__empty"><?php esc_html_e( 'No gigs listed here yet.', 'mbe-gigs' ); ?></p>
	<?php endif; ?>

</main>
<?php
if ( $mbe_bb_theme ) {
	echo '</div></div></div>';
}

get_footer();
