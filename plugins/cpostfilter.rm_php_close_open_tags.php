<?php

/**
 * string rm_php_close_open_tags(CTE, string)
 * Removes unnecessary PHP close-open tags.
 */
function CTEPL__rm_php_close_open_tags($string)
{
  return preg_replace('/\?>\s*<\?php/i', '', $string);
}
?>