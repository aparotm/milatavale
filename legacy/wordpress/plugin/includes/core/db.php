<?php
if (!defined('ABSPATH')) { exit; }

final class MLV2_DB {

    public static function maybe_install(): void {
        global $wpdb;

        // Crea tablas nuevas si el plugin fue actualizado sin re-activar.
        // Validamos TODAS las tablas críticas.
        $tables = [
            self::table_movimientos(),
            self::table_clientes_almacenes(),
            self::table_alerts(),
            $wpdb->prefix . 'mlv_audit_log',
        ];

        foreach ($tables as $t) {
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t));
            if ($exists !== $t) {
                self::activate();
                break;
            }
        }
        // Migraciones de columnas (ALTER TABLE) sin reactivar el plugin.
        self::maybe_migrate_columns();
    }


    
    public static function maybe_migrate_columns(): void {
        global $wpdb;
        $table = self::table_movimientos();

        // Si la tabla no existe, no hacer nada (activate() se encargará).
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) {
            return;
        }

        // Columna valor_por_lata para trazabilidad histórica del precio.
        $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'valor_por_lata'));
        if (!$col) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN valor_por_lata BIGINT(20) NOT NULL DEFAULT 0 AFTER cantidad_latas");
        }

        // === Nuevas columnas para separar origen y trazabilidad contable ===
        $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'origen_saldo'));
        if (!$col) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN origen_saldo VARCHAR(20) NOT NULL DEFAULT 'reciclaje' AFTER monto_calculado");
        }

        $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'mov_ref_id'));
        if (!$col) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN mov_ref_id BIGINT(20) UNSIGNED NULL DEFAULT NULL AFTER origen_saldo");
        }

        $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'is_system_adjustment'));
        if (!$col) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN is_system_adjustment TINYINT(1) NOT NULL DEFAULT 0 AFTER mov_ref_id");
        }

        $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'clasificacion_mov'));
        if (!$col) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN clasificacion_mov VARCHAR(30) NOT NULL DEFAULT 'operacion' AFTER is_system_adjustment");
        }

        $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'incentivo_batch_id'));
        if (!$col) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN incentivo_batch_id VARCHAR(64) NULL DEFAULT NULL AFTER clasificacion_mov");
        }

        // Índices nuevos (si faltan)
        $idx = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", 'idx_origen_saldo'));
        if (!$idx) { $wpdb->query("ALTER TABLE {$table} ADD KEY idx_origen_saldo (origen_saldo)"); }

        $idx = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", 'idx_mov_ref_id'));
        if (!$idx) { $wpdb->query("ALTER TABLE {$table} ADD KEY idx_mov_ref_id (mov_ref_id)"); }

        $idx = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", 'idx_clasificacion_mov'));
        if (!$idx) { $wpdb->query("ALTER TABLE {$table} ADD KEY idx_clasificacion_mov (clasificacion_mov)"); }

        $idx = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", 'idx_incentivo_batch_id'));
        if (!$idx) { $wpdb->query("ALTER TABLE {$table} ADD KEY idx_incentivo_batch_id (incentivo_batch_id)"); }

        // Índices de rendimiento para consultas de panel/admin frecuentes.
        $idx = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", 'idx_created_by'));
        if (!$idx) { $wpdb->query("ALTER TABLE {$table} ADD KEY idx_created_by (created_by_user_id)"); }

        $idx = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", 'idx_cliente_deleted'));
        if (!$idx) { $wpdb->query("ALTER TABLE {$table} ADD KEY idx_cliente_deleted (cliente_user_id, deleted_at)"); }

        $idx = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", 'idx_local_deleted_created'));
        if (!$idx) { $wpdb->query("ALTER TABLE {$table} ADD KEY idx_local_deleted_created (local_codigo, deleted_at, created_at)"); }

        // Backfill seguro: defaults para registros existentes
        $updated = 0;
        $updated += (int) $wpdb->query(
            "UPDATE {$table}
             SET origen_saldo = 'reciclaje'
             WHERE (origen_saldo IS NULL OR origen_saldo = '')"
        );
        $updated += (int) $wpdb->query(
            "UPDATE {$table}
             SET clasificacion_mov = 'operacion'
             WHERE (clasificacion_mov IS NULL OR clasificacion_mov = '')"
        );

        // Backfill opcional recomendado: marcar incentivos históricos por palabras clave en detalle/observación
        $updated_incentivo = (int) $wpdb->query(
            "UPDATE {$table}
             SET origen_saldo = 'incentivo'
             WHERE monto_calculado > 0
               AND (detalle LIKE '%incentivo%' OR detalle LIKE '%incentivos pasados%')"
        );

        $updated_reg = (int) $wpdb->query(
            "UPDATE {$table}
             SET clasificacion_mov = 'regularizacion_historica'
             WHERE monto_calculado > 0
               AND (detalle LIKE '%latas pasadas%' OR detalle LIKE '%regularizacion%')"
        );

        if (($updated + $updated_incentivo + $updated_reg) > 0 && class_exists('MLV2_Audit')) {
            MLV2_Audit::add('movimientos_backfill_origen_clasificacion', 'movimiento', 0, null, [
                'updated_defaults' => $updated,
                'updated_incentivo' => $updated_incentivo,
                'updated_regularizacion' => $updated_reg,
            ]);
        }

        // Backfill seguro (solo si calza exacto): valor_por_lata = monto_calculado / cantidad_latas
        // Solo para ingresos con latas > 0.
        $wpdb->query(
            "UPDATE {$table}
             SET valor_por_lata = (monto_calculado / cantidad_latas)
             WHERE valor_por_lata = 0
               AND tipo = 'ingreso'
               AND cantidad_latas > 0
               AND (monto_calculado % cantidad_latas) = 0"
        );
    }

public static function table_movimientos(): string {
        global $wpdb;
        return $wpdb->prefix . 'mlv_movimientos';
    }

    

    

    public static function table_clientes_almacenes(): string {
        global $wpdb;
        return $wpdb->prefix . 'mlv_clientes_almacenes';
    }
public static function table_alerts(): string {
        global $wpdb;
        return $wpdb->prefix . 'mlv_alerts';
    }

public static function activate(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table = self::table_movimientos();

        $sql = "CREATE TABLE $table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            tipo VARCHAR(20) NOT NULL DEFAULT 'ingreso',
            cliente_user_id BIGINT(20) UNSIGNED NOT NULL,
            cliente_rut VARCHAR(20) NULL,
            cliente_telefono VARCHAR(30) NULL,
            local_codigo VARCHAR(50) NOT NULL,
            cantidad_latas INT(11) NOT NULL DEFAULT 0,
            valor_por_lata BIGINT(20) NOT NULL DEFAULT 0,
            monto_calculado BIGINT(20) NOT NULL DEFAULT 0,
            origen_saldo VARCHAR(20) NOT NULL DEFAULT 'reciclaje',
            mov_ref_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            is_system_adjustment TINYINT(1) NOT NULL DEFAULT 0,
            clasificacion_mov VARCHAR(30) NOT NULL DEFAULT 'operacion',
            incentivo_batch_id VARCHAR(64) NULL DEFAULT NULL,
            estado VARCHAR(30) NOT NULL,
            detalle LONGTEXT NULL,
            created_by_user_id BIGINT(20) UNSIGNED NOT NULL,
            validated_by_user_id BIGINT(20) UNSIGNED NULL,
            deleted_at DATETIME NULL,
            deleted_by BIGINT(20) UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_estado (estado),
            KEY idx_local (local_codigo),
            KEY idx_cliente (cliente_user_id),
            KEY idx_rut (cliente_rut),
            KEY idx_deleted (deleted_at),
            KEY idx_created_by (created_by_user_id),
            KEY idx_cliente_deleted (cliente_user_id, deleted_at),
            KEY idx_local_deleted_created (local_codigo, deleted_at, created_at),
            KEY idx_origen_saldo (origen_saldo),
            KEY idx_mov_ref_id (mov_ref_id),
            KEY idx_clasificacion_mov (clasificacion_mov),
            KEY idx_incentivo_batch_id (incentivo_batch_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Relación N-N: clientes asociados a múltiples almacenes
        $table_ca = self::table_clientes_almacenes();
        $sql_ca = "CREATE TABLE $table_ca (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            cliente_user_id BIGINT(20) UNSIGNED NOT NULL,
            local_codigo VARCHAR(50) NOT NULL,
            created_by_user_id BIGINT(20) UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_cliente_local (cliente_user_id, local_codigo),
            KEY idx_local (local_codigo),
            KEY idx_cliente (cliente_user_id)
        ) $charset;";
        dbDelta($sql_ca);

        // Alerts (notificaciones)
        $alerts = self::table_alerts();
        $sql2 = "CREATE TABLE $alerts (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'info',
            message LONGTEXT NOT NULL,
            ref_type VARCHAR(50) NOT NULL DEFAULT '',
            ref_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            is_dismissed TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            dismissed_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY is_dismissed (is_dismissed),
            KEY ref (ref_type, ref_id)
        ) $charset;";
        dbDelta($sql2);

        // Audit log (inmutable)
        $audit = $wpdb->prefix . 'mlv_audit_log';
        $sql3 = "CREATE TABLE $audit (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NULL,
            user_role VARCHAR(100) NOT NULL DEFAULT '',
            ip VARCHAR(64) NOT NULL DEFAULT '',
            action VARCHAR(50) NOT NULL,
            object_type VARCHAR(50) NOT NULL,
            object_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            before_data LONGTEXT NULL,
            after_data LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_action (action),
            KEY idx_object (object_type, object_id),
            KEY idx_user (user_id)
        ) $charset;";
        dbDelta($sql3);
    }

    public static function deactivate(): void {
        // Intencionalmente no hacemos nada: el ledger nunca se borra.
    }
}
