-- K-Docs - Index de Performance
-- À exécuter sur la base de données kdocs

-- =============================================
-- INDEX POUR DOCUMENTS (table principale)
-- =============================================

-- Index sur le statut (filtrage fréquent)
CREATE INDEX IF NOT EXISTS idx_documents_status
ON documents(status);

-- Index sur ocr_status (queue de traitement)
CREATE INDEX IF NOT EXISTS idx_documents_ocr_status
ON documents(ocr_status);

-- Index sur deleted_at (soft delete, très fréquent)
CREATE INDEX IF NOT EXISTS idx_documents_deleted_at
ON documents(deleted_at);

-- Index composite pour listing standard (deleted + created)
CREATE INDEX IF NOT EXISTS idx_documents_list
ON documents(deleted_at, created_at DESC);

-- Index sur correspondent_id (filtrage par correspondant)
CREATE INDEX IF NOT EXISTS idx_documents_correspondent
ON documents(correspondent_id);

-- Index sur document_type_id (filtrage par type)
CREATE INDEX IF NOT EXISTS idx_documents_type
ON documents(document_type_id);

-- Index sur created_at (tri chronologique)
CREATE INDEX IF NOT EXISTS idx_documents_created
ON documents(created_at DESC);

-- Index sur doc_date (recherche par date document)
CREATE INDEX IF NOT EXISTS idx_documents_doc_date
ON documents(doc_date);

-- Index sur owner_id/created_by (filtrage par propriétaire)
CREATE INDEX IF NOT EXISTS idx_documents_owner
ON documents(created_by);

-- =============================================
-- INDEX POUR DOCUMENT_TAGS (jointures)
-- =============================================

-- Index sur tag_id pour recherche par tag
CREATE INDEX IF NOT EXISTS idx_document_tags_tag
ON document_tags(tag_id);

-- =============================================
-- INDEX POUR TAGS
-- =============================================

-- Index sur name pour recherche/autocomplete
CREATE INDEX IF NOT EXISTS idx_tags_name
ON tags(name);

-- =============================================
-- INDEX POUR CORRESPONDENTS
-- =============================================

-- Index sur name pour recherche/autocomplete
CREATE INDEX IF NOT EXISTS idx_correspondents_name
ON correspondents(name);

-- =============================================
-- INDEX POUR AUDIT_LOGS
-- =============================================

-- Index sur created_at (pagination chronologique)
CREATE INDEX IF NOT EXISTS idx_audit_logs_created
ON audit_logs(created_at DESC);

-- Index sur user_id (filtrage par utilisateur)
CREATE INDEX IF NOT EXISTS idx_audit_logs_user
ON audit_logs(user_id);

-- Index sur entity_type + entity_id (historique d'une entité)
CREATE INDEX IF NOT EXISTS idx_audit_logs_entity
ON audit_logs(entity_type, entity_id);

-- =============================================
-- INDEX POUR CHAT_CONVERSATIONS
-- =============================================

-- Index sur user_id + updated_at (liste conversations récentes)
CREATE INDEX IF NOT EXISTS idx_chat_conversations_user
ON chat_conversations(user_id, updated_at DESC);

-- =============================================
-- INDEX POUR CHAT_MESSAGES
-- =============================================

-- Index sur conversation_id (messages d'une conversation)
CREATE INDEX IF NOT EXISTS idx_chat_messages_conversation
ON chat_messages(conversation_id);

-- =============================================
-- INDEX POUR TASKS
-- =============================================

-- Index sur status (filtrage tâches en cours)
CREATE INDEX IF NOT EXISTS idx_tasks_status
ON tasks(status);

-- Index sur assigned_to (tâches d'un utilisateur)
CREATE INDEX IF NOT EXISTS idx_tasks_assigned
ON tasks(assigned_to);

-- Index sur due_date (tâches à échéance)
CREATE INDEX IF NOT EXISTS idx_tasks_due
ON tasks(due_date);

-- =============================================
-- INDEX POUR WORKFLOW_EXECUTIONS
-- =============================================

-- Index sur status (workflows en cours)
CREATE INDEX IF NOT EXISTS idx_workflow_exec_status
ON workflow_executions(status);

-- Index sur document_id (workflow d'un document)
CREATE INDEX IF NOT EXISTS idx_workflow_exec_document
ON workflow_executions(document_id);
