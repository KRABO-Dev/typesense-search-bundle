<?php
/**
 * Copyright (C) 2025  Jaap Jansma (jaap.jansma@civicoop.org)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

$GLOBALS['TL_DCA']['tl_settings']['fields']['krabo_typesense_enabled'] = [
  'inputType'               => 'checkbox',
  'eval'                    => array('tl_class'=>'w50 clr')
];
$GLOBALS['TL_DCA']['tl_settings']['fields']['krabo_typesense_protocol'] = [
  'inputType'               => 'text',
  'eval'                    => array('tl_class'=>'w50 clr', 'mandatory' => true)
];
$GLOBALS['TL_DCA']['tl_settings']['fields']['krabo_typesense_host'] = [
  'inputType'               => 'text',
  'eval'                    => array('tl_class'=>'w50 clr', 'mandatory' => true)
];
$GLOBALS['TL_DCA']['tl_settings']['fields']['krabo_typesense_port'] = [
  'inputType'               => 'text',
  'eval'                    => array('tl_class'=>'w50 clr', 'mandatory' => true)
];
$GLOBALS['TL_DCA']['tl_settings']['fields']['krabo_typesense_index_api_key'] = [
  'inputType'               => 'text',
  'eval'                    => array('tl_class'=>'w50 clr', 'mandatory' => true)
];
$GLOBALS['TL_DCA']['tl_settings']['fields']['krabo_typesense_search_api_key'] = [
  'inputType'               => 'text',
  'eval'                    => array('tl_class'=>'w50 clr', 'mandatory' => true)
];
$GLOBALS['TL_DCA']['tl_settings']['fields']['krabo_typesense_collection_prefix'] = [
  'inputType'               => 'text',
  'eval'                    => array('tl_class'=>'w50 clr', 'mandatory' => true)
];

\Contao\CoreBundle\DataContainer\PaletteManipulator::create()
  ->addLegend('krabo_typesense_legend')
  ->addField('krabo_typesense_enabled')
  ->addField('krabo_typesense_protocol')
  ->addField('krabo_typesense_host')
  ->addField('krabo_typesense_port')
  ->addField('krabo_typesense_index_api_key')
  ->addField('krabo_typesense_search_api_key')
  ->addField('krabo_typesense_collection_prefix')
  ->applyToPalette('default', 'tl_settings');