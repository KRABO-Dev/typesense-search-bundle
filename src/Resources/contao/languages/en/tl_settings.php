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

$GLOBALS['TL_LANG']['tl_settings']['krabo_typesense_legend'] = 'Typesense';
$GLOBALS['TL_LANG']['tl_settings']['krabo_typesense_enabled'] = ['Enable Typesense', ''];
$GLOBALS['TL_LANG']['tl_settings']['krabo_typesense_protocol'] = ['Protocol', 'http/https'];
$GLOBALS['TL_LANG']['tl_settings']['krabo_typesense_host'] = ['Host', ''];
$GLOBALS['TL_LANG']['tl_settings']['krabo_typesense_port'] = ['Port', ''];
$GLOBALS['TL_LANG']['tl_settings']['krabo_typesense_index_api_key'] = ['Key used for indexing', ''];
$GLOBALS['TL_LANG']['tl_settings']['krabo_typesense_search_api_key'] = ['Key used for searching', 'This key will be leaked on the website. Use a key which is only allowed to search.'];
$GLOBALS['TL_LANG']['tl_settings']['krabo_typesense_collection_prefix'] = ['Prefix of the collection', 'E.g.. prod_'];