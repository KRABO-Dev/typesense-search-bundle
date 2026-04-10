<?php

/**
 * Frontend modules
 */

use Krabo\TypesenseSearchBundle\Modules\TypesenseAutocompleteModule;
use Krabo\TypesenseSearchBundle\Modules\TypesenseSearchModule;

array_insert($GLOBALS['BE_MOD']['system'], 3, array
(
  'tl_typesense_collection' => array
  (
    'tables'            => array('tl_typesense_collection'),
  ),
));

array_insert($GLOBALS['FE_MOD'], 3, [
    'typesense-search-modules' => [
      'typesense_autocomplete' => TypesenseAutocompleteModule::class,
      'typesense_search' => TypesenseSearchModule::class,
    ]
]);

if (TL_MODE == 'BE')
{
  $GLOBALS['TL_CSS'][] = 'bundles/typesensesearch/assets/css/typesense.css|static';
  $GLOBALS['TL_JAVASCRIPT'][] = 'bundles/typesensesearch/assets/js/typesense.js|static';
}

$GLOBALS['TL_HOOKS']['executePreActions'][] = array('Krabo\TypesenseSearchBundle\Hooks\executeIsoProductIndex', 'ajaxHandler');