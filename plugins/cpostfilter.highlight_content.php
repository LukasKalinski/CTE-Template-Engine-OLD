<?php

/**
 * string highlight_content(CTE, string)
 * Removes unnecessary PHP close-open tags.
 */
function CTEPL__highlight_content($string)
{
  return highlight_string($string, true);
}
?>