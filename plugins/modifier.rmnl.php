<?php
/**
 * mixed rmnl(mixed)
 * Remove new-line characters.
 *
 * @param mixed  &$target
 */
function CTEPL__rmnl($target)
{
  return preg_replace("/\n|\r/", '', $target);
}
?>