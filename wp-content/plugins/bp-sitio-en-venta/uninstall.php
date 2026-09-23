<?php

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('bp_sitio_en_venta');
delete_transient('bp_sitio_en_venta_config');
