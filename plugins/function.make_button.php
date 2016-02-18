<?php
require_once('Cylib/functions/parse_ini.php');
require_once('lib/lib.btngen.php');
require_once('lib/function.get_avail_themes.php');

/**
 * @desc CTE Plugin function for button creation.
 *
 * Case 1:    import_env == true
 *            Imports make_button environment (static widths of button left and right wrappers etc) into <system data source>.plugin.make_button.*.
 * Case 2:    import_env == false
 *            Generates a button image and returns its filepath.
 *
 * Modes:     Force eval only.
 * Arguments: [Type] [Name]               [Required] [Default]      [Description]
 *            string  label                X                         The label to print on the button.
 *            string  type                 X                         Button type.
 *            string  icon_l                                         Left icon (only one icon).
 *            string  icon_r                                         Right icon (only one icon).
 *            bool    ext                             true           If true filename will be returned with extension, if false without extension.
 *
 * @param string $scope
 * @param mixed[] $args
 */
function CTEPL__make_button($scope, $args)
{
  if($scope != CTE::SYSDS_KEY_STATIC)
    CTE::handle_error('Plugin:make_button can only be called in force-eval mode.', ERR_USER_ERROR);
  
  extract($args, EXTR_PREFIX_ALL|EXTR_REFS, 'arg');
  
  if(!isset($arg_type) || empty($arg_type))
    CTE::handle_error('Missing (or empty) argument <i>type</i> for plugin make_button.', ERR_USER_ERROR);
  
  $arg_import_env = (isset($arg_import_env) && $arg_import_env == true);
  
  if(!$arg_import_env)
  {
    if(!isset($arg_label) || empty($arg_label))
      CTE::handle_error('Missing (or empty) argument <i>label</i> for plugin make_button.', ERR_USER_ERROR);
    if(isset($arg_icon_r) && isset($arg_icon_l))
      CTE::handle_error('Cannot have both left and right icons.', ERR_USER_ERROR);
    
    // Set defaults for optional arguments:
    if(!isset($arg_ext) || !is_bool($arg_ext))
      $arg_ext = true;
    
    $button_filename = md5($arg_label.'>'.(isset($arg_icon_r) || isset($arg_icon_l) ? 'icon' : '').'>'.$arg_type);
    
    // Build return string:
    $return = $button_filename . ($arg_ext ? '.gif' : ''); // Add filename extension if requested.
    
    $button_filename .= '.gif';
  }
  else
  {
    if(!is_bool($arg_import_env))
      CTE::handle_error('Invalid value for import_env.', ERR_USER_ERROR);
    $return = '';
  }
  
  $static_image_token_size = array(); // Theme dependent button token size container.
  
  $themes = CTEPL_LIB__get_avail_themes();
  foreach($themes as $theme_data)
  {
    // Check if theme is enabled.
    if($theme_data['enabled'] != 1)
      continue;
    
    if(!$arg_import_env)
    {
      // Initiate output dir:
      $full_gfx_output_path = PATH_SYS__GFX_OUTPUT_ROOT . $theme_data['id'] . '/' . $theme_data['hash'] . '/~btn/';
      
      // Create output dir if not found.
      if(!is_dir($full_gfx_output_path)) mkdir($full_gfx_output_path);
    }
    
    // Check for left icon.
    if(!$arg_import_env && isset($arg_icon_l) && !empty($arg_icon_l))
      $image_L_src = PATH_SYS__GFX_LIB_ROOT.'theme/'.$theme_data['id'].'/button/icon/btnicon_L_'.$arg_icon_l.'.gif';
    else
      $image_L_src = PATH_SYS__GFX_LIB_ROOT.'theme/'.$theme_data['id'].'/button/'.$arg_type.'_L.gif';
    
    $image_C_src = PATH_SYS__GFX_LIB_ROOT.'theme/'.$theme_data['id'].'/button/'.$arg_type.'_C.gif';
    
    // Check for right icon.
    if(!$arg_import_env && isset($arg_icon_r) && !empty($arg_icon_r))
      $image_R_src = PATH_SYS__GFX_LIB_ROOT.'theme/'.$theme_data['id'].'/button/icon/btnicon_R_'.$arg_icon_r.'.gif';
    else
      $image_R_src = PATH_SYS__GFX_LIB_ROOT.'theme/'.$theme_data['id'].'/button/'.$arg_type.'_R.gif';
    
    // Create image button.
    $button = new Button($image_L_src, $image_C_src, $image_R_src, 3);
    
    // Collect relevant properties in CTE plugins env and continue if that's what is requested:
    if($arg_import_env)
    {
      array_push($static_image_token_size, $theme_data['id'].':'.($button->get_token_size(1) + $button->get_token_size(3)));
      continue;
    }
    
    $text_color = $theme_data['button'][$arg_type.'__text_color'];
    $text_shadow_color_key = $arg_type.'__text_shadow_color';
    $text_shadow_color = (key_exists($text_shadow_color_key, $theme_data['button']) ? $theme_data['button'][$text_shadow_color_key] : NULL);
    
    $charset_image = PATH_SYS__GFX_LIB_ROOT.($theme_data['button']['charset_image'] == 'default' ? 'default' : 'theme/'.$theme_data['id']).'/charset.gif';
    
    // Write image to file.
    imagegif($button->build($charset_image, $arg_label, $text_color, $text_shadow_color), $full_gfx_output_path.$button_filename);
    CTE::set_sysds_plugin_entry('make_button', 'lasttextsizex', $button->get_token_size(2), CTE::SYSDS_KEY_STATIC);
  }
  CTE::set_sysds_plugin_entry('make_button', $arg_type.'_staticsizex', implode(',', $static_image_token_size), CTE::SYSDS_KEY_STATIC);
  
  return $return;
}
?>
