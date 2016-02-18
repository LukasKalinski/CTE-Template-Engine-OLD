<?php
/**
 * Interface for CTE configs.
 *
 * @package CTE
 * @since 2006-06-14
 * @version 2006-06-14
 * @copyright Cylab 2006-2007
 * @author Lukas Kalinski
 */

interface CTE_Config
{
  public function set_env($key, $value);
  public function get_env($key);
  public function set_plugin_cfg($plugin, $key, $value);
  public function get_plugin_cfg($plugin, $key);
}
?>