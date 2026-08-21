<!doctype html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="site-wrap">
<header class="site-header">
  <div class="container header-inner">
    <div class="site-branding">
      <?php if (has_custom_logo()) : ?>
        <div class="site-logo"><?php the_custom_logo(); ?></div>
      <?php endif; ?>
      <?php if (get_theme_mod('ifp_show_site_title', true) || get_theme_mod('ifp_show_site_tagline', true)) : ?>
        <a href="<?php echo esc_url(home_url('/')); ?>">
          <?php if (get_theme_mod('ifp_show_site_title', true)) : ?>
            <div class="site-title"><?php bloginfo('name'); ?></div>
          <?php endif; ?>

          <?php if (get_theme_mod('ifp_show_site_tagline', true) && get_bloginfo('description')) : ?>
            <div class="site-description"><?php bloginfo('description'); ?></div>
          <?php endif; ?>
        </a>
      <?php endif; ?>
    </div>

    <button class="menu-toggle" aria-expanded="false" aria-controls="primary-menu">Menu</button>

    <nav class="main-navigation" id="primary-menu" aria-label="<?php esc_attr_e('Primary Menu', 'ice-field-productions'); ?>">
      <?php
      wp_nav_menu(array(
        'theme_location' => 'primary',
        'container'      => false,
        'fallback_cb'    => 'ifp_default_menu',
      ));
      ?>
    </nav>

    <?php
    $header_cta_url = get_theme_mod('ifp_header_cta_url', '#');
    $header_cta_text = get_theme_mod('ifp_header_cta_text', 'Buy Tickets');
    if ($header_cta_url && $header_cta_text) :
    ?>
      <a class="button button--nav header-cta" href="<?php echo esc_url($header_cta_url); ?>">
        <?php echo esc_html($header_cta_text); ?>
      </a>
    <?php endif; ?>
  </div>

  <?php if (is_active_sidebar('header-utility')) : ?>
    <div class="header-utility">
      <div class="container"><?php dynamic_sidebar('header-utility'); ?></div>
    </div>
  <?php endif; ?>
</header>
