-- ============================================================
-- Durcissement du journal d'audit — APPEND-ONLY
-- À appliquer après schema_coffre_fort.sql.
-- ============================================================

-- Fonction trigger : toute tentative de mutation lève une erreur SQL.
CREATE OR REPLACE FUNCTION audit_log_append_only()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    -- Un trigger TRUNCATE est au niveau instruction : OLD n'y est pas affecté.
    IF TG_OP = 'TRUNCATE' THEN
        RAISE EXCEPTION
            'audit_log est append-only : TRUNCATE est interdit'
            USING ERRCODE = 'insufficient_privilege';
    END IF;

    RAISE EXCEPTION
        'audit_log est append-only : % est interdit (evenement id=%)',
        TG_OP,
        COALESCE(OLD.id, 0)
        USING ERRCODE = 'insufficient_privilege';
END;
$$;

-- UPDATE / DELETE ligne par ligne
DROP TRIGGER IF EXISTS trg_audit_log_no_mutation ON audit_log;
CREATE TRIGGER trg_audit_log_no_mutation
    BEFORE UPDATE OR DELETE ON audit_log
    FOR EACH ROW
    EXECUTE FUNCTION audit_log_append_only();

-- TRUNCATE (opération d'ensemble)
DROP TRIGGER IF EXISTS trg_audit_log_no_truncate ON audit_log;
CREATE TRIGGER trg_audit_log_no_truncate
    BEFORE TRUNCATE ON audit_log
    FOR EACH STATEMENT
    EXECUTE FUNCTION audit_log_append_only();

-- Réduction des privilèges par défaut. En production, l'application devrait
-- utiliser un rôle dédié avec INSERT + SELECT uniquement sur audit_log.
REVOKE UPDATE, DELETE, TRUNCATE ON audit_log FROM PUBLIC;

COMMENT ON TABLE audit_log IS
    'Journal append-only : INSERT uniquement, protégé par triggers (UPDATE/DELETE/TRUNCATE interdits).';
