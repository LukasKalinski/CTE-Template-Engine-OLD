<?php

/**
 * string highlight_content(CTE, string)
 * Removes unnecessary PHP close-open tags.
 */
function CTEPL__rm_image_linebreaks($string)
{
  $string = preg_replace('/\s+(<img .*? \/>)\s+/', '$1', $string);
  return $string;
}
?>