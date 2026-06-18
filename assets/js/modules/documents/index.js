window.TaskFlowDocuments = (function () {
    function getDefaultTemplateForm(content) {
        return {
            id: null,
            name: '',
            slug: '',
            description: '',
            category: 'CRM',
            output_format: 'html',
            source_origin: 'inline',
            source_path: '',
            docx_priority: 0,
            docx_priority_label: '',
            docx_readiness: '',
            docx_readiness_label: '',
            docx_practical_use: '',
            docx_limitations: [],
            docx_recommended_tokens: [],
            docx_token_notes: [],
            docx_studied_basis: '',
            docx_studied: false,
            content: content || '<section style="font-family:Inter,system-ui,sans-serif;padding:32px"><h1>{{client.name}}</h1><p>{{client.email}}</p><div>{{tasks.table}}</div></section>'
        };
    }

    function mapTemplateToForm(template, fallbackContent) {
        return {
            id: template.id,
            name: template.name || '',
            slug: template.slug || '',
            description: template.description || '',
            category: template.category || 'CRM',
            output_format: template.output_format || 'html',
            source_origin: template.source_origin || 'inline',
            source_path: template.source_path || '',
            docx_priority: template.docx_priority || 0,
            docx_priority_label: template.docx_priority_label || '',
            docx_readiness: template.docx_readiness || '',
            docx_readiness_label: template.docx_readiness_label || '',
            docx_practical_use: template.docx_practical_use || '',
            docx_limitations: template.docx_limitations || [],
            docx_recommended_tokens: template.docx_recommended_tokens || [],
            docx_token_notes: template.docx_token_notes || [],
            docx_studied_basis: template.docx_studied_basis || '',
            docx_studied: !!template.docx_studied,
            content: template.content || fallbackContent || ''
        };
    }

    function applyPreviewData(ctx, fieldsData, emptyMessage) {
        ctx.documentFieldGroups = fieldsData?.groups || [];
        ctx.documentsDocxSupport = fieldsData?.docx || null;
        if (fieldsData?.preview) {
            ctx.documentsPreviewContext = fieldsData.preview;
            ctx.documentsPreviewJson = JSON.stringify(fieldsData.preview, null, 2);
            return;
        }

        ctx.documentsPreviewContext = null;
        ctx.documentsPreviewJson = emptyMessage;
    }

    function resetBatchResult(ctx) {
        ctx.documentsBatchArchive = null;
        ctx.documentsBatchWarning = '';
    }

    return {
        async reload(ctx) {
            ctx.documentsIsLoading = true;
            ctx.documentsLoadError = '';
            try {
                const selectedClientId = ctx.documentsSelectedClientId || ctx.crmClientId || '';
                const [templatesRes, clientsRes, fieldsRes, historyRes] = await Promise.all([
                    apiDocumentsTemplates(),
                    apiDocumentsClients(),
                    apiDocumentsFields(selectedClientId),
                    apiDocumentsHistory({ client_id: selectedClientId, limit: 20 })
                ]);

                if (templatesRes?.success) {
                    ctx.documentTemplates = (templatesRes.data || []).slice().sort((a, b) => {
                        const aDocx = String(a?.output_format || 'html') === 'docx' ? 1 : 0;
                        const bDocx = String(b?.output_format || 'html') === 'docx' ? 1 : 0;
                        if (aDocx !== bDocx) return bDocx - aDocx;
                        const aPriority = Number(a?.docx_priority || 0);
                        const bPriority = Number(b?.docx_priority || 0);
                        if (aPriority !== bPriority) return bPriority - aPriority;
                        return String(a?.name || '').localeCompare(String(b?.name || ''), 'ru');
                    });
                    if (!ctx.documentsSelectedTemplateId && ctx.documentTemplates[0]?.id) {
                        this.selectTemplate(ctx, ctx.documentTemplates[0].id);
                    } else if (ctx.documentsSelectedTemplateId) {
                        ctx.documentsSelectedTemplate = (ctx.documentTemplates || []).find(t => String(t.id) === String(ctx.documentsSelectedTemplateId)) || null;
                    }
                }

                if (clientsRes?.success) {
                    ctx.documentClients = clientsRes.data || [];
                    if (ctx.documentsSelectedClientId) {
                        const selectedClient = (ctx.documentClients || []).find(c => String(c.id) === String(ctx.documentsSelectedClientId));
                        ctx.documentsSelectedClientName = selectedClient?.name || '';
                    } else if (ctx.crmClientId) {
                        const selectedClient = (ctx.documentClients || []).find(c => String(c.id) === String(ctx.crmClientId));
                        if (selectedClient) {
                            ctx.documentsSelectedClientId = String(selectedClient.id);
                            ctx.documentsSelectedClientName = selectedClient.name || '';
                        }
                    }
                }

                if (fieldsRes?.success) {
                    applyPreviewData(ctx, fieldsRes.data, 'Нет данных для предпросмотра');
                }

                if (historyRes?.success) {
                    ctx.documentGenerationHistory = historyRes.data || [];
                    ctx.documentsHistoryError = '';
                }
            } catch (e) {
                console.error('Documents reload error', e);
                ctx.documentsLoadError = e?.message || 'Не удалось загрузить шаблоны и данные для документов';
                ctx.documentFieldGroups = [];
                ctx.documentsPreviewContext = null;
                ctx.documentsPreviewJson = 'Не удалось загрузить данные для предпросмотра.';
                ctx.documentsDocxSupport = null;
                ctx.documentGenerationHistory = [];
            } finally {
                ctx.documentsIsLoading = false;
            }
        },

        selectTemplate(ctx, templateId) {
            ctx.documentsSelectedTemplateId = templateId;
            ctx.documentsSelectedTemplate = (ctx.documentTemplates || []).find(t => String(t.id) === String(templateId)) || null;
            if (!ctx.documentsSelectedTemplate) return;

            ctx.documentTemplateForm = mapTemplateToForm(ctx.documentsSelectedTemplate, ctx.documentTemplateForm.content);
            if (!ctx.documentsSelectedTemplate.content) {
                this.loadTemplate(ctx, ctx.documentsSelectedTemplate.id);
            }
        },

        async loadTemplate(ctx, templateId) {
            if (!templateId) return;
            try {
                const res = await apiGet(`documents/templates/${templateId}`);
                if (res?.success && res.data) {
                    const full = res.data;
                    const index = (ctx.documentTemplates || []).findIndex(t => String(t.id) === String(templateId));
                    if (index >= 0) ctx.documentTemplates[index] = full;
                    ctx.documentsSelectedTemplate = full;
                    ctx.documentTemplateForm = mapTemplateToForm(full);
                }
            } catch (e) {
                ctx.showToast('Не удалось загрузить шаблон', 'error');
            }
        },

        openTemplateEditor(ctx, template) {
            if (template?.id) {
                this.selectTemplate(ctx, template.id);
                return;
            }
            this.resetTemplateEditor(ctx);
        },

        resetTemplateEditor(ctx) {
            ctx.documentsSelectedTemplateId = null;
            ctx.documentsSelectedTemplate = null;
            ctx.documentTemplateForm = getDefaultTemplateForm();
        },

        async saveTemplate(ctx) {
            if (ctx.documentTemplateForm.output_format === 'docx') {
                ctx.showToast('DOCX-шаблоны из папки docs пока не редактируются через интерфейс', 'warning');
                return;
            }

            if (!ctx.documentTemplateForm.name || !ctx.documentTemplateForm.content) {
                ctx.showToast('Заполните название и HTML шаблон', 'error');
                return;
            }

            const payload = {
                name: ctx.documentTemplateForm.name,
                slug: ctx.documentTemplateForm.slug,
                description: ctx.documentTemplateForm.description,
                category: ctx.documentTemplateForm.category,
                content: ctx.documentTemplateForm.content,
                output_format: ctx.documentTemplateForm.output_format || 'html',
                source_origin: ctx.documentTemplateForm.source_origin || 'inline',
                source_path: ctx.documentTemplateForm.source_path || '',
                is_active: 1
            };

            try {
                let res;
                if (ctx.documentTemplateForm.id) {
                    res = await apiPut(`documents/templates/${ctx.documentTemplateForm.id}`, payload);
                } else {
                    res = await apiPost('documents/templates', payload);
                }

                if (res?.success) {
                    ctx.showToast('Шаблон сохранён', 'success');
                    await this.reload(ctx);
                    if (res.data?.id) {
                        await this.loadTemplate(ctx, res.data.id);
                        ctx.documentsSelectedTemplateId = res.data.id;
                    }
                }
            } catch (e) {
                ctx.showToast('Ошибка сохранения шаблона: ' + (e.message || ''), 'error');
            }
        },

        toggleBatchTemplate(ctx, templateId) {
            const normalizedId = Number(templateId);
            const exists = (ctx.documentsBatchTemplateIds || []).some(id => Number(id) === normalizedId);
            if (exists) {
                ctx.documentsBatchTemplateIds = (ctx.documentsBatchTemplateIds || []).filter(id => Number(id) !== normalizedId);
                return;
            }
            ctx.documentsBatchTemplateIds = [...(ctx.documentsBatchTemplateIds || []), normalizedId];
        },

        async onClientChange(ctx) {
            const selectedClient = (ctx.documentClients || []).find(c => String(c.id) === String(ctx.documentsSelectedClientId));
            ctx.documentsSelectedClientName = selectedClient?.name || '';
            resetBatchResult(ctx);
            ctx.documentsLoadError = '';
            ctx.documentsHistoryError = '';
            ctx.documentsIsLoading = true;
            try {
                const [fieldsRes, historyRes] = await Promise.all([
                    apiDocumentsFields(ctx.documentsSelectedClientId),
                    apiDocumentsHistory({ client_id: ctx.documentsSelectedClientId, limit: 20 })
                ]);
                if (fieldsRes?.success) {
                    applyPreviewData(ctx, fieldsRes.data, 'Нет данных для предпросмотра');
                }
                if (historyRes?.success) {
                    ctx.documentGenerationHistory = historyRes.data || [];
                }
            } catch (e) {
                ctx.documentFieldGroups = [];
                ctx.documentsDocxSupport = null;
                ctx.documentsPreviewContext = null;
                ctx.documentsPreviewJson = ctx.documentsSelectedClientId
                    ? 'Не удалось загрузить данные выбранного клиента для документов.'
                    : 'Выберите клиента, чтобы увидеть доступные данные.';
                ctx.documentGenerationHistory = [];
                ctx.documentsLoadError = e?.message || 'Не удалось загрузить данные клиента для шаблонов';
                ctx.documentsHistoryError = 'История по выбранному клиенту сейчас недоступна.';
                ctx.showToast('Не удалось загрузить данные клиента для шаблонов', 'error');
            } finally {
                ctx.documentsIsLoading = false;
            }
        },

        buildSourcePayload(ctx) {
            const source = ctx.documentsSourceContext || null;
            if (!source?.entity_type || !source?.entity_id) {
                return {};
            }
            return {
                source_entity_type: source.entity_type,
                source_entity_id: source.entity_id
            };
        },

        resetSourceContext(ctx) {
            ctx.documentsSourceContext = null;
        },

        historySourceLabel(_ctx, item) {
            if (!item?.source_entity_type || !item?.source_entity_id) return '';
            const labels = {
                task: 'из задачи',
                deal: 'из сделки',
                client: 'из клиента',
                project: 'из проекта'
            };
            return `${labels[item.source_entity_type] || item.source_entity_type} #${item.source_entity_id}`;
        },

        openClientsDirectory(ctx) {
            ctx.currentView = 'crm-clients';
            ctx.ensureCrmLoaded();
        },

        async refreshHistory(ctx) {
            ctx.documentsIsHistoryLoading = true;
            ctx.documentsHistoryError = '';
            try {
                const res = await apiDocumentsHistory({ client_id: ctx.documentsSelectedClientId, limit: 20 });
                if (res?.success) {
                    ctx.documentGenerationHistory = res.data || [];
                }
            } catch (e) {
                console.warn('Documents history refresh error', e);
                ctx.documentsHistoryError = e?.message || 'Не удалось обновить историю генераций';
            } finally {
                ctx.documentsIsHistoryLoading = false;
            }
        },

        generationFeedback(_ctx, item) {
            if (!item) return '';
            if (item.generation_note) return item.generation_note;
            const replacements = item.docx_replacements || null;
            if (replacements && typeof replacements.tokens_replaced !== 'undefined') {
                return `DOCX: заменено токенов ${Number(replacements.tokens_replaced || 0)}, обработано файлов ${Number(replacements.files_processed || 0)}.`;
            }
            if (item.preview_html) {
                return 'HTML-документ собран и сохранен.';
            }
            return '';
        },

        copyField(ctx, fieldKey) {
            return ctx.copyText(`{{${fieldKey}}}`);
        },

        fieldBestForLabel(_ctx, field) {
            const mode = String(field?.best_for || 'both');
            if (mode === 'docx') return 'лучше для DOCX';
            if (mode === 'html') return 'лучше для HTML';
            return 'HTML и DOCX';
        },

        fieldBadgeClass(_ctx, field) {
            const mode = String(field?.best_for || 'both');
            if (mode === 'docx') return 'background:#ecfdf5;color:#047857;border:1px solid #a7f3d0';
            if (mode === 'html') return 'background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe';
            return 'background:#f8fafc;color:#475569;border:1px solid #dbe3ee';
        },

        templateReadinessBadgeStyle(_ctx, template) {
            const readiness = String(template?.docx_readiness || 'unknown');
            if (readiness === 'adaptable') return 'background:#ecfdf5;color:#047857;border:1px solid #a7f3d0';
            if (readiness === 'partial') return 'background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe';
            if (readiness === 'limited') return 'background:#fff7ed;color:#c2410c;border:1px solid #fdba74';
            return 'background:#f8fafc;color:#475569;border:1px solid #dbe3ee';
        },

        templateLimitations(_ctx, template) {
            return Array.isArray(template?.docx_limitations) ? template.docx_limitations : [];
        },

        templateTokenMap(_ctx, template) {
            const items = template?.docx_recommended_tokens;
            return Array.isArray(items) ? items : [];
        },

        templateTokenNotes(_ctx, template) {
            const items = template?.docx_token_notes;
            return Array.isArray(items) ? items : [];
        },

        docxRecommendedTokens(ctx) {
            const selectedMap = this.templateTokenMap(ctx, ctx.documentsSelectedTemplate);
            if (selectedMap.length) {
                return selectedMap.map(item => item?.token).filter(Boolean);
            }
            const groups = ctx.documentFieldGroups || [];
            const tokens = [];
            groups.forEach(group => {
                (group.fields || []).forEach(field => {
                    if (field?.docx_supported) tokens.push(field.token || `{{${field.key}}}`);
                });
            });
            return tokens.slice(0, 12);
        },

        async importTemplate(ctx, event) {
            const file = event?.target?.files?.[0];
            if (!file) return;
            try {
                const content = await file.text();
                const baseName = String(file.name || 'template').replace(/\.[^.]+$/, '');
                ctx.documentTemplateForm = {
                    ...getDefaultTemplateForm(content),
                    name: baseName,
                    description: 'Импортировано из файла ' + file.name
                };
                ctx.documentsSelectedTemplateId = null;
                ctx.documentsSelectedTemplate = null;
                ctx.showToast('Шаблон загружен в редактор', 'success');
            } catch (e) {
                ctx.showToast('Не удалось прочитать файл шаблона', 'error');
            } finally {
                if (event?.target) event.target.value = '';
            }
        },

        async generateSingle(ctx) {
            if (!ctx.documentsSelectedTemplateId || !ctx.documentsSelectedClientId) {
                ctx.showToast('Выберите шаблон и клиента', 'error');
                return;
            }

            try {
                ctx.documentsIsGenerating = true;
                const res = await apiDocumentsGenerate({
                    template_id: ctx.documentsSelectedTemplateId,
                    client_id: ctx.documentsSelectedClientId,
                    ...this.buildSourcePayload(ctx)
                });
                if (res?.success && res.data) {
                    ctx.documentGeneratedItems = [res.data, ...(ctx.documentGeneratedItems || [])];
                    resetBatchResult(ctx);
                    ctx.documentsLastGenerationMeta = res.data;
                    await this.refreshHistory(ctx);
                    ctx.showToast('Документ сформирован', 'success');
                    if (res.data?.generation_note) {
                        ctx.showToast(res.data.generation_note, 'warning');
                    }
                }
            } catch (e) {
                ctx.showToast('Ошибка генерации документа: ' + (e.message || ''), 'error');
            } finally {
                ctx.documentsIsGenerating = false;
            }
        },

        isDocxTemplate(ctx, template) {
            const current = template || ctx.documentsSelectedTemplate || null;
            return (current?.output_format || '') === 'docx';
        },

        async generateBatch(ctx) {
            if (!ctx.documentsSelectedClientId || !(ctx.documentsBatchTemplateIds || []).length) {
                ctx.showToast('Выберите клиента и шаблоны для пакета', 'error');
                return;
            }

            try {
                ctx.documentsIsGenerating = true;
                const res = await apiDocumentsGenerateBatch({
                    client_id: ctx.documentsSelectedClientId,
                    template_ids: ctx.documentsBatchTemplateIds,
                    ...this.buildSourcePayload(ctx)
                });
                if (res?.success) {
                    ctx.documentGeneratedItems = [...(res.data?.items || []), ...(ctx.documentGeneratedItems || [])];
                    ctx.documentsBatchArchive = res.data?.archive || null;
                    ctx.documentsBatchWarning = res.data?.archive?.warning || '';
                    ctx.documentsLastGenerationMeta = (res.data?.items || [])[0] || null;
                    await this.refreshHistory(ctx);
                    ctx.showToast(`Пакет сформирован: ${res.data?.count || 0} шт.`, 'success');
                    if (ctx.documentsBatchWarning) {
                        ctx.showToast(ctx.documentsBatchWarning, 'warning');
                    }
                    const docxNote = (res.data?.items || []).find(item => item?.generation_note)?.generation_note;
                    if (docxNote) {
                        ctx.showToast(docxNote, 'warning');
                    }
                }
            } catch (e) {
                ctx.showToast('Ошибка генерации пакета: ' + (e.message || ''), 'error');
            } finally {
                ctx.documentsIsGenerating = false;
            }
        },

        downloadBatch(ctx) {
            if (ctx.documentsBatchArchive?.file_url) {
                window.open(ctx.documentsBatchArchive.file_url, '_blank');
                return;
            }

            const items = ctx.documentGeneratedItems || [];
            if (!items.length) return;
            items.forEach((item, index) => {
                setTimeout(() => {
                    window.open(item.file_url, '_blank');
                }, index * 150);
            });
        },

        openForClient(ctx, client) {
            const clientId = client?.id || ctx.crmClientId || '';
            const clientName = client?.name || ctx.crmClient?.client?.name || '';
            ctx.currentView = 'documents';
            ctx.ensureCrmLoaded();
            ctx.documentsSelectedClientId = clientId ? String(clientId) : '';
            ctx.documentsSelectedClientName = clientName || '';
            resetBatchResult(ctx);
            ctx.documentsSourceContext = null;
            ctx.$nextTick(async () => {
                await this.reload(ctx);
                if (ctx.documentsSelectedClientId) {
                    await this.onClientChange(ctx);
                }
            });
        },

        openForTask(ctx, task) {
            if (!task) return;
            const resolvedClientId = task.client_id || task.crm_client_id || '';
            const directDealClientId = task.deal_client_id || '';
            const dealClientId = task.deal_id
                ? ((ctx.crmDeals || []).find(d => String(d.id) === String(task.deal_id))?.client_id || '')
                : '';
            const clientId = resolvedClientId || directDealClientId || dealClientId;
            if (!clientId) {
                ctx.showToast('У задачи нет привязки к клиенту', 'warning');
                return;
            }

            const clientName = task.client_name
                || task.deal_client_name
                || (ctx.crmClients || []).find(c => String(c.id) === String(clientId))?.name
                || (ctx.crmDeals || []).find(d => String(d.id) === String(task.deal_id))?.client_name
                || '';

            ctx.currentView = 'documents';
            ctx.ensureCrmLoaded();
            ctx.documentsSelectedClientId = String(clientId);
            ctx.documentsSelectedClientName = clientName || '';
            resetBatchResult(ctx);
            ctx.documentsSourceContext = {
                entity_type: 'task',
                entity_id: Number(task.id),
                task_id: Number(task.id),
                label: task.title || ('Задача #' + task.id)
            };
            ctx.$nextTick(async () => {
                await this.reload(ctx);
                await this.onClientChange(ctx);
            });
        },

        taskCanOpenDocuments(ctx, task) {
            if (!task) return false;
            if (task.client_id || task.crm_client_id || task.deal_client_id) return true;
            if (task.deal_id) {
                return (ctx.crmDeals || []).some(d => String(d.id) === String(task.deal_id) && d.client_id);
            }
            return false;
        }
    };
})();
