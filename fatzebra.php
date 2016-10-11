<?php
/*
Plugin Name: Gravity Forms Fat Zebra Add-On
Plugin URI: http://github.com/fatzebra/GravityForms-FatZebra
Description: Accept credit card payments through Gravity Forms, simply with Fat Zebra
Version: 1.0.1
Author: Matthew Savage
Author URI: https://www.fatzebra.com.au

------------------------------------------------------------------------
Copyright 2012 Fat Zebra Pty. Ltd.
last updated: July 31, 2012

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/


define( 'GF_FZ_VERSION', '1.0.1' );

add_action( 'gform_loaded', array( 'GF_FatZebra_Bootstrap', 'load' ), 5 );

class GF_FatZebra_Bootstrap {

    public static function load(){

        if ( ! method_exists( 'GFForms', 'include_payment_addon_framework' ) ) {
            return;
        }

        require_once( 'class-gf-fatzebra.php' );

        GFAddOn::register( 'GFFatZebra' );
    }

}

function gf_fatzebra(){
    return GFFatZebra::get_instance();
}