<?php

declare(strict_types=1);
/**
 * Global operations
 */
$GLOBALS['TL_DCA']['tl_iso_product']['list' ]['global_operations']['typesense'] = array
(
	'label'				=> &$GLOBALS['TL_LANG']['tl_iso_product']['typesense'],
	'button_callback'	=> function() {
    return '<a href="'.TL_PATH.'/typesense/isotopeproduct/index" onclick="Backend.getScrollOffset();IsoProductTypesense.openModalSelector({\'width\':250,\'height\':200,\'title\':\'Refresh Typesense Index\',\'url\':this.href,\'id\':\'refresh-typesense\'});return false" class="header_iso_typesense isotope-tools">'.$GLOBALS['TL_LANG']['tl_iso_product']['typesense'].'</a>';
  },
	'attributes'		=> 'onclick="Backend.getScrollOffset();"',
);
