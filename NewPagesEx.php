<?php

/* NewPagesEx extension
 * Copyright (c) 2011, Vitaliy Filippov <vitalif[d.o.g]mail.ru>
 * License: GPLv3.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

# This extension replaces the stock Special:NewPages page
# with an improved version, which supports:
# - parsed article content in feed items description
# - feed caching
# - selection of pages by category

$wgExtensionCredits['specialpage'][] = array(
    'name'           => 'NewPagesEx',
    'version'        => '2011-05-12',
    'author'         => 'Vitaliy Filippov',
    'url'            => 'http://wiki.4intra.net/NewPagesEx',
    'description'    => 'Replaces stock Special:NewPages with more advanced version',
);

$dir = dirname(__FILE__);
$wgAutoloadClasses += array(
    'SpecialNewPagesEx' => "$dir/NewPagesEx.class.php",
    'NewPagesExPager'   => "$dir/NewPagesEx.class.php",
);
$wgSpecialPages['Newpages'] = 'SpecialNewPagesEx';
$wgExtensionMessagesFiles['NewPagesEx'] = "$dir/NewPagesEx.i18n.php";
