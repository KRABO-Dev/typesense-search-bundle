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

namespace Krabo\TypesenseSearchBundle\DataContainer\Driver;

use Contao\DC_Table;

class DC_Typesense extends  DC_Table {

  public function addWhere($statement, $value) {
    $this->procedure[] = $statement;
    $this->values[] = $value;
  }

  /**
   * Return an object property
   *
   * @param string $strKey
   *
   * @return mixed
   */
  public function __get($strKey)
  {
    switch ($strKey)
    {
      case 'parentTable':
        return $this->ptable;

      case 'childTable':
        return $this->ctable;

      case 'rootIds':
        return $this->root;
    }

    return parent::__get($strKey);
  }

}