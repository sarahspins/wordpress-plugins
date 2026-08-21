<?php
if (!defined('ABSPATH')) exit;
function ifp_setup(){add_theme_support('title-tag');add_theme_support('post-thumbnails');add_theme_support('custom-logo');add_theme_support('align-wide');add_theme_support('responsive-embeds');add_theme_support('html5',array('search-form','gallery','caption','style','script'));register_nav_menus(array('primary'=>__('Primary Menu','ice-field-productions'),'footer'=>__('Footer Menu','ice-field-productions')));}add_action('after_setup_theme','ifp_setup');
function ifp_assets(){wp_enqueue_style('ifp-style',get_stylesheet_uri(),array(),'1.3.2');wp_enqueue_script('ifp-script',get_template_directory_uri().'/assets/theme.js',array(),'1.3.2',true);}add_action('wp_enqueue_scripts','ifp_assets');
function ifp_register_production_type(){register_post_type('production',array('labels'=>array('name'=>__('Productions','ice-field-productions'),'singular_name'=>__('Production','ice-field-productions'),'add_new_item'=>__('Add New Production','ice-field-productions'),'edit_item'=>__('Edit Production','ice-field-productions')),'public'=>true,'has_archive'=>true,'rewrite'=>array('slug'=>'productions'),'menu_icon'=>'dashicons-tickets-alt','supports'=>array('title','editor','excerpt','thumbnail','custom-fields'),'show_in_rest'=>true));}add_action('init','ifp_register_production_type');
function ifp_customize_register($wp_customize){$wp_customize->add_section('ifp_home',array('title'=>__('Show Homepage','ice-field-productions'),'priority'=>30));$settings=array('ifp_show_kicker'=>array('Current Production','Hero Eyebrow'),'ifp_show_title'=>array('Once Upon a Time','Show Title'),'ifp_show_dates'=>array('August 15–16, 2026','Show Dates'),'ifp_show_intro'=>array('Join us for an unforgettable theatrical journey on ice.','Hero Introduction'),'ifp_ticket_url'=>array('#','Ticket URL'),'ifp_register_url'=>array('#','Registration URL'),'ifp_opening_date'=>array('2026-08-15T16:00:00','Opening Date and Time'));foreach($settings as $key=>$data){$wp_customize->add_setting($key,array('default'=>$data[0],'sanitize_callback'=>'sanitize_text_field'));$wp_customize->add_control($key,array('label'=>__($data[1],'ice-field-productions'),'section'=>'ifp_home','type'=>'text'));}$wp_customize->add_setting('ifp_hero_image',array('sanitize_callback'=>'absint'));$wp_customize->add_control(new WP_Customize_Media_Control($wp_customize,'ifp_hero_image',array('label'=>__('Hero Image','ice-field-productions'),'section'=>'ifp_home','mime_type'=>'image')));}add_action('customize_register','ifp_customize_register');
function ifp_get_hero_image(){$id=get_theme_mod('ifp_hero_image');return $id?wp_get_attachment_image_url($id,'full'):'';}
function ifp_default_menu(){echo '<ul><li><a href="'.esc_url(home_url('/')).'">Home</a></li><li><a href="'.esc_url(home_url('/productions/')).'">The Show</a></li><li><a href="'.esc_url(home_url('/participant-hub/')).'">Participants</a></li><li><a href="'.esc_url(home_url('/tickets/')).'">Audience</a></li><li><a href="'.esc_url(home_url('/support/')).'">Support</a></li><li><a class="button--nav" href="'.esc_url(get_theme_mod('ifp_ticket_url','#')).'">Buy Tickets</a></li></ul>';}


/**
 * Editable header and footer widget areas.
 */
function ifp_widgets_init() {
  register_sidebar(array(
    'name'          => __('Header Utility Area', 'ice-field-productions'),
    'id'            => 'header-utility',
    'description'   => __('Optional content displayed beneath the main header navigation.', 'ice-field-productions'),
    'before_widget' => '<div class="header-utility-widget">',
    'after_widget'  => '</div>',
    'before_title'  => '<h2 class="screen-reader-text">',
    'after_title'   => '</h2>',
  ));

  register_sidebar(array(
    'name'          => __('Footer Introduction', 'ice-field-productions'),
    'id'            => 'footer-intro',
    'description'   => __('Replaces the default footer logo/title and introductory text.', 'ice-field-productions'),
    'before_widget' => '<div class="footer-widget footer-widget--intro">',
    'after_widget'  => '</div>',
    'before_title'  => '<h3>',
    'after_title'   => '</h3>',
  ));

  for ($i = 1; $i <= 4; $i++) {
    register_sidebar(array(
      'name'          => sprintf(__('Footer Column %d', 'ice-field-productions'), $i),
      'id'            => 'footer-' . $i,
      'description'   => __('Add a Navigation Menu, text, image, or other block. When empty, the theme uses its default column.', 'ice-field-productions'),
      'before_widget' => '<div class="footer-widget">',
      'after_widget'  => '</div>',
      'before_title'  => '<h4>',
      'after_title'   => '</h4>',
    ));
  }

  register_sidebar(array(
    'name'          => __('Footer Bottom', 'ice-field-productions'),
    'id'            => 'footer-bottom',
    'description'   => __('Optional copyright, legal, or organization details displayed at the very bottom.', 'ice-field-productions'),
    'before_widget' => '<div class="footer-bottom-widget">',
    'after_widget'  => '</div>',
    'before_title'  => '<h4 class="screen-reader-text">',
    'after_title'   => '</h4>',
  ));
}
add_action('widgets_init', 'ifp_widgets_init');

/**
 * Header and footer Customizer controls.
 */
function ifp_header_footer_customize($wp_customize) {
  $wp_customize->add_section('ifp_header', array(
    'title'    => __('Header Options', 'ice-field-productions'),
    'priority' => 31,
  ));

  $wp_customize->add_setting('ifp_header_cta_text', array(
    'default'           => 'Buy Tickets',
    'sanitize_callback' => 'sanitize_text_field',
  ));
  $wp_customize->add_control('ifp_header_cta_text', array(
    'label'   => __('Header Button Text', 'ice-field-productions'),
    'section' => 'ifp_header',
    'type'    => 'text',
  ));

  $wp_customize->add_setting('ifp_header_cta_url', array(
    'default'           => '#',
    'sanitize_callback' => 'esc_url_raw',
  ));
  $wp_customize->add_control('ifp_header_cta_url', array(
    'label'       => __('Header Button URL', 'ice-field-productions'),
    'description' => __('Leave blank to hide the button.', 'ice-field-productions'),
    'section'     => 'ifp_header',
    'type'        => 'url',
  ));

  $wp_customize->add_setting('ifp_sticky_header', array(
    'default'           => true,
    'sanitize_callback' => 'ifp_sanitize_checkbox',
  ));
  $wp_customize->add_control('ifp_sticky_header', array(
    'label'   => __('Use sticky header', 'ice-field-productions'),
    'section' => 'ifp_header',
    'type'    => 'checkbox',
  ));

  $wp_customize->add_setting('ifp_header_background', array(
    'default'           => '#003b5c',
    'sanitize_callback' => 'sanitize_hex_color',
  ));
  $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'ifp_header_background', array(
    'label'   => __('Header Background', 'ice-field-productions'),
    'section' => 'ifp_header',
  )));


  $wp_customize->add_setting('ifp_header_title_color', array(
    'default'           => '#ffffff',
    'sanitize_callback' => 'sanitize_hex_color',
  ));
  $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'ifp_header_title_color', array(
    'label'   => __('Site Title Color', 'ice-field-productions'),
    'section' => 'ifp_header',
  )));

  $wp_customize->add_setting('ifp_header_tagline_color', array(
    'default'           => '#b7c9d4',
    'sanitize_callback' => 'sanitize_hex_color',
  ));
  $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'ifp_header_tagline_color', array(
    'label'   => __('Tagline Color', 'ice-field-productions'),
    'section' => 'ifp_header',
  )));

  $wp_customize->add_setting('ifp_header_menu_color', array(
    'default'           => '#ffffff',
    'sanitize_callback' => 'sanitize_hex_color',
  ));
  $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'ifp_header_menu_color', array(
    'label'   => __('Menu Text Color', 'ice-field-productions'),
    'section' => 'ifp_header',
  )));

  $wp_customize->add_setting('ifp_show_site_title', array(
    'default'           => true,
    'sanitize_callback' => 'ifp_sanitize_checkbox',
  ));
  $wp_customize->add_control('ifp_show_site_title', array(
    'label'   => __('Show site title', 'ice-field-productions'),
    'section' => 'ifp_header',
    'type'    => 'checkbox',
  ));

  $wp_customize->add_setting('ifp_show_site_tagline', array(
    'default'           => true,
    'sanitize_callback' => 'ifp_sanitize_checkbox',
  ));
  $wp_customize->add_control('ifp_show_site_tagline', array(
    'label'   => __('Show site tagline', 'ice-field-productions'),
    'section' => 'ifp_header',
    'type'    => 'checkbox',
  ));

  $wp_customize->add_section('ifp_footer', array(
    'title'    => __('Footer Options', 'ice-field-productions'),
    'priority' => 32,
  ));

  $wp_customize->add_setting('ifp_footer_heading', array(
    'default'           => '',
    'sanitize_callback' => 'sanitize_text_field',
  ));
  $wp_customize->add_control('ifp_footer_heading', array(
    'label'       => __('Footer Heading Override', 'ice-field-productions'),
    'description' => __('Leave blank to use the website title.', 'ice-field-productions'),
    'section'     => 'ifp_footer',
    'type'        => 'text',
  ));

  $wp_customize->add_setting('ifp_footer_intro', array(
    'default'           => 'Celebrating skating, storytelling, and unforgettable moments on ice.',
    'sanitize_callback' => 'sanitize_textarea_field',
  ));
  $wp_customize->add_control('ifp_footer_intro', array(
    'label'   => __('Footer Introduction', 'ice-field-productions'),
    'section' => 'ifp_footer',
    'type'    => 'textarea',
  ));

  $wp_customize->add_setting('ifp_footer_background', array(
    'default'           => '#003b5c',
    'sanitize_callback' => 'sanitize_hex_color',
  ));
  $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'ifp_footer_background', array(
    'label'   => __('Footer Background', 'ice-field-productions'),
    'section' => 'ifp_footer',
  )));

  $wp_customize->add_setting('ifp_footer_accent', array(
    'default'           => '#ffc600',
    'sanitize_callback' => 'sanitize_hex_color',
  ));
  $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'ifp_footer_accent', array(
    'label'   => __('Footer Heading Color', 'ice-field-productions'),
    'section' => 'ifp_footer',
  )));

  $wp_customize->add_setting('ifp_footer_copyright', array(
    'default'           => 'Ice & Field at The Crossover',
    'sanitize_callback' => 'sanitize_text_field',
  ));
  $wp_customize->add_control('ifp_footer_copyright', array(
    'label'   => __('Footer Copyright / Organization Text', 'ice-field-productions'),
    'section' => 'ifp_footer',
    'type'    => 'text',
  ));
}
add_action('customize_register', 'ifp_header_footer_customize', 20);

function ifp_sanitize_checkbox($checked) {
  return (isset($checked) && true == $checked);
}

function ifp_customizer_css() {
  $header_bg = sanitize_hex_color(get_theme_mod('ifp_header_background', '#003b5c'));
  $header_title = sanitize_hex_color(get_theme_mod('ifp_header_title_color', '#ffffff'));
  $header_tagline = sanitize_hex_color(get_theme_mod('ifp_header_tagline_color', '#b7c9d4'));
  $header_menu = sanitize_hex_color(get_theme_mod('ifp_header_menu_color', '#ffffff'));
  $footer_bg = sanitize_hex_color(get_theme_mod('ifp_footer_background', '#003b5c'));
  $footer_accent = sanitize_hex_color(get_theme_mod('ifp_footer_accent', '#ffc600'));
  $sticky = get_theme_mod('ifp_sticky_header', true);
  ?>
  <style id="ifp-customizer-css">
    .site-header {
      background: <?php echo esc_html($header_bg ?: '#003b5c'); ?>;
      <?php if (!$sticky) : ?>position: relative;<?php endif; ?>
    }
    .site-header .site-title {
      color: <?php echo esc_html($header_title ?: '#ffffff'); ?>;
    }
    .site-header .site-description {
      color: <?php echo esc_html($header_tagline ?: '#b7c9d4'); ?>;
    }
    .site-header .main-navigation > ul > li > a,
    .site-header .menu-toggle {
      color: <?php echo esc_html($header_menu ?: '#ffffff'); ?>;
    }
    .site-footer { background: <?php echo esc_html($footer_bg ?: '#003b5c'); ?>; }
    .site-footer h3,
    .site-footer h4 { color: <?php echo esc_html($footer_accent ?: '#ffc600'); ?>; }
  </style>
  <?php
}
add_action('wp_head', 'ifp_customizer_css');


/**
 * Block editor support and reusable homepage patterns.
 */
function ifp_block_editor_setup() {
  add_theme_support('editor-styles');
  add_editor_style('style.css');
  add_theme_support('wp-block-styles');
  add_theme_support('wide-align');

  register_block_pattern_category('ice-field-productions', array(
    'label' => __('Ice & Field Productions', 'ice-field-productions'),
  ));
}
add_action('after_setup_theme', 'ifp_block_editor_setup', 20);

function ifp_register_patterns() {
  if (!function_exists('register_block_pattern')) return;

  register_block_pattern('ice-field-productions/hero', array(
    'title'       => __('Show Hero', 'ice-field-productions'),
    'description' => __('Large theatrical hero with title, dates, and two buttons.', 'ice-field-productions'),
    'categories'  => array('ice-field-productions'),
    'content'     => '<!-- wp:cover {"url":"","dimRatio":0,"minHeight":680,"className":"ifp-hero-block","align":"full"} -->
<div class="wp-block-cover alignfull ifp-hero-block" style="min-height:680px"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-0 has-background-dim"></span><div class="wp-block-cover__inner-container"><!-- wp:group {"className":"ifp-block-container","layout":{"type":"constrained"}} -->
<div class="wp-block-group ifp-block-container"><!-- wp:paragraph {"style":{"typography":{"letterSpacing":"0.14em","textTransform":"uppercase"}},"textColor":"luminous-vivid-amber","fontSize":"small"} -->
<p class="has-luminous-vivid-amber-color has-text-color has-small-font-size" style="letter-spacing:0.14em;text-transform:uppercase"><strong>Current Production</strong></p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":1,"textColor":"white"} -->
<h1 class="wp-block-heading has-white-color has-text-color">Once Upon a Time</h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"textColor":"white","fontSize":"large"} -->
<p class="has-white-color has-text-color has-large-font-size">August 15–16, 2026<br>Join us for an unforgettable theatrical journey on ice.</p>
<!-- /wp:paragraph -->
<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"vivid-red","className":"is-style-fill"} -->
<div class="wp-block-button is-style-fill"><a class="wp-block-button__link has-vivid-red-background-color has-background wp-element-button">Buy Tickets</a></div>
<!-- /wp:button -->
<!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button">Join the Cast</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group --></div></div>
<!-- /wp:cover -->',
  ));

  register_block_pattern('ice-field-productions/path-cards', array(
    'title'      => __('Join Watch Support Cards', 'ice-field-productions'),
    'categories' => array('ice-field-productions'),
    'content'    => '<!-- wp:group {"className":"ifp-block-section","layout":{"type":"constrained"}} -->
<div class="wp-block-group ifp-block-section"><!-- wp:group {"className":"ifp-block-container","layout":{"type":"constrained"}} -->
<div class="wp-block-group ifp-block-container"><!-- wp:paragraph {"textColor":"vivid-red","fontSize":"small"} -->
<p class="has-vivid-red-color has-text-color has-small-font-size"><strong>CHOOSE YOUR PATH</strong></p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">Join. Watch. Support.</h2>
<!-- /wp:heading -->
<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:cover {"dimRatio":50,"minHeight":330,"className":"ifp-path-card"} -->
<div class="wp-block-cover ifp-path-card" style="min-height:330px"><span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span><div class="wp-block-cover__inner-container"><!-- wp:heading {"level":3,"textColor":"white"} -->
<h3 class="wp-block-heading has-white-color has-text-color">Join the Show</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"textColor":"white"} -->
<p class="has-white-color has-text-color">Registration, rehearsals, costumes, and participant resources.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"textColor":"white"} -->
<p class="has-white-color has-text-color"><strong>Participant information →</strong></p>
<!-- /wp:paragraph --></div></div>
<!-- /wp:cover --></div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column"><!-- wp:cover {"dimRatio":50,"minHeight":330,"className":"ifp-path-card"} -->
<div class="wp-block-cover ifp-path-card" style="min-height:330px"><span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span><div class="wp-block-cover__inner-container"><!-- wp:heading {"level":3,"textColor":"white"} -->
<h3 class="wp-block-heading has-white-color has-text-color">Watch the Show</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"textColor":"white"} -->
<p class="has-white-color has-text-color">Tickets, showtimes, seating, parking, and accessibility.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"textColor":"white"} -->
<p class="has-white-color has-text-color"><strong>Plan your visit →</strong></p>
<!-- /wp:paragraph --></div></div>
<!-- /wp:cover --></div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column"><!-- wp:cover {"dimRatio":50,"minHeight":330,"className":"ifp-path-card"} -->
<div class="wp-block-cover ifp-path-card" style="min-height:330px"><span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span><div class="wp-block-cover__inner-container"><!-- wp:heading {"level":3,"textColor":"white"} -->
<h3 class="wp-block-heading has-white-color has-text-color">Support the Show</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"textColor":"white"} -->
<p class="has-white-color has-text-color">Sponsors, program ads, volunteers, and community support.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"textColor":"white"} -->
<p class="has-white-color has-text-color"><strong>Support the production →</strong></p>
<!-- /wp:paragraph --></div></div>
<!-- /wp:cover --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->',
  ));

  register_block_pattern('ice-field-productions/story', array(
    'title'      => __('Production Story', 'ice-field-productions'),
    'categories' => array('ice-field-productions'),
    'content'    => '<!-- wp:group {"backgroundColor":"luminous-vivid-amber","className":"ifp-block-section","layout":{"type":"constrained"}} -->
<div class="wp-block-group ifp-block-section has-luminous-vivid-amber-background-color has-background"><!-- wp:columns {"className":"ifp-block-container","verticalAlignment":"center"} -->
<div class="wp-block-columns are-vertically-aligned-center ifp-block-container"><!-- wp:column {"verticalAlignment":"center"} -->
<div class="wp-block-column is-vertically-aligned-center"><!-- wp:image {"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img alt="Production artwork"/></figure>
<!-- /wp:image --></div>
<!-- /wp:column -->
<!-- wp:column {"verticalAlignment":"center"} -->
<div class="wp-block-column is-vertically-aligned-center"><!-- wp:group {"className":"ifp-content-card","layout":{"type":"constrained"}} -->
<div class="wp-block-group ifp-content-card"><!-- wp:paragraph {"textColor":"vivid-red","fontSize":"small"} -->
<p class="has-vivid-red-color has-text-color has-small-font-size"><strong>THIS YEAR\'S PRODUCTION</strong></p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">A story brought to life on ice</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Introduce the current production in a warm, theatrical way. Explain the theme, the audience experience, and what makes participation special.</p>
<!-- /wp:paragraph -->
<!-- wp:button {"backgroundColor":"vivid-red"} -->
<div class="wp-block-button"><a class="wp-block-button__link has-vivid-red-background-color has-background wp-element-button">Explore the Production</a></div>
<!-- /wp:button --></div>
<!-- /wp:group --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group -->',
  ));

  register_block_pattern('ice-field-productions/participant-hub', array(
    'title'      => __('Participant Hub', 'ice-field-productions'),
    'categories' => array('ice-field-productions'),
    'content'    => '<!-- wp:group {"className":"ifp-block-section","layout":{"type":"constrained"}} -->
<div class="wp-block-group ifp-block-section"><!-- wp:columns {"className":"ifp-block-container"} -->
<div class="wp-block-columns ifp-block-container"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:group {"className":"ifp-content-card","layout":{"type":"constrained"}} -->
<div class="wp-block-group ifp-content-card"><!-- wp:paragraph {"textColor":"vivid-red","fontSize":"small"} -->
<p class="has-vivid-red-color has-text-color has-small-font-size"><strong>PARTICIPANT HUB</strong></p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">Everything your skater needs</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Keep rehearsals, costumes, announcements, program information, and important deadlines in one predictable location.</p>
<!-- /wp:paragraph -->
<!-- wp:buttons {"className":"ifp-quick-links"} -->
<div class="wp-block-buttons ifp-quick-links"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Rehearsal Schedule</a></div>
<!-- /wp:button -->
<!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Costume Information</a></div>
<!-- /wp:button -->
<!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Important Dates</a></div>
<!-- /wp:button -->
<!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Participant FAQs</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group --></div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading -->
<h2 class="wp-block-heading">Important moments</h2>
<!-- /wp:heading -->
<!-- wp:list -->
<ul class="wp-block-list"><li><strong>Registration:</strong> Cast registration opens</li><li><strong>Rehearsals:</strong> Weekly rehearsals begin</li><li><strong>Dress Rehearsal:</strong> Full production run-through</li><li><strong>Opening Night:</strong> The curtain rises</li></ul>
<!-- /wp:list --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group -->',
  ));
}
add_action('init', 'ifp_register_patterns');
