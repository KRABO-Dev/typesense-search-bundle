<?php
/**
 * Copyright (C) 2026  Jaap Jansma (jaap.jansma@civicoop.org)
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

namespace Krabo\TypesenseSearchBundle\Event;

use Krabo\TypesenseSearchBundle\Modules\AbstractTypesenseModule;
use Krabo\TypesenseSearchBundle\Typesense;

class TypesenseFECollectionEvent {

  public array $collection;

  public AbstractTypesenseModule $module;

  public function __construct(array $collection, AbstractTypesenseModule $module) {
    $this->collection = $collection;
    $this->module = $module;
  }

}