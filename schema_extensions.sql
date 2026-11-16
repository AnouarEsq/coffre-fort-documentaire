-- ============================================================
-- Extensions non destructives du schéma fourni
-- ============================================================
-- Le retrait d'un document est LOGIQUE : on conserve la ligne documents
-- afin que les entrées audit_log (clé étrangère document_id) restent valides
-- et que le journal demeure strictement append-only. Le fichier .enc est,
-- lui, effacé du stockage.

ALTER TABLE documents
    ADD COLUMN IF NOT EXISTS supprime_le TIMESTAMPTZ;

CREATE INDEX IF NOT EXISTS idx_documents_actifs
    ON documents (created_at DESC)
    WHERE supprime_le IS NULL;

COMMENT ON COLUMN documents.supprime_le IS
    'Date de retrait logique. NULL = document actif. Le fichier chiffre est efface au retrait.';
