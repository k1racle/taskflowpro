window.TaskFlowKnowledge = (function () {
    function getKnowledgeFormDefaults() {
        return { title: '', content: '', department_id: '' };
    }

    function getKnowledgeMediaFormDefaults(article = null) {
        return {
            video_title: article?.type === 'video' ? (article.title || '') : '',
            video_url: article?.type === 'video' ? (article.url || '') : '',
            slides_title: article?.type === 'slides' ? (article.title || '') : '',
            slides_url: article?.type === 'slides' ? (article.url || '') : '',
            faq_question: article?.type === 'faq' ? (article.question || '') : '',
            faq_answer: article?.type === 'faq' ? (article.answer || '') : ''
        };
    }

    function normalizeKnowledgeType(type) {
        return type || 'article';
    }

    function buildKnowledgePayload(ctx) {
        const tab = ctx.knowledgeModalTab;

        return {
            type: tab,
            title: tab === 'article'
                ? (ctx.knowledgeForm.title || '').trim()
                : (tab === 'faq' ? '' : (ctx.knowledgeMediaForm[`${tab}_title`] || '').trim()),
            content: tab === 'article' ? (ctx.knowledgeForm.content || '') : '',
            url: (tab === 'video' ? ctx.knowledgeMediaForm.video_url : (tab === 'slides' ? ctx.knowledgeMediaForm.slides_url : null)) || null,
            question: tab === 'faq' ? (ctx.knowledgeMediaForm.faq_question || '').trim() : null,
            answer: tab === 'faq' ? (ctx.knowledgeMediaForm.faq_answer || '') : null,
            department_id: ctx.knowledgeForm.department_id || null
        };
    }

    return {
        async loadKnowledge(ctx) {
            try {
                const data = await apiGetKnowledge();
                if (data.success) ctx.knowledgeArticles = data.data || [];
            } catch (error) {
                console.error('Ошибка загрузки базы знаний:', error);
            }
        },

        openKnowledgeModal(ctx, article = null) {
            ctx.editingKnowledge = article;

            if (article) {
                ctx.knowledgeForm = {
                    title: article.title,
                    content: article.content || '',
                    department_id: article.department_id || ''
                };
            } else {
                ctx.knowledgeForm = getKnowledgeFormDefaults();
            }

            ctx.knowledgeModalTab = normalizeKnowledgeType(article?.type);
            ctx.knowledgeMediaForm = getKnowledgeMediaFormDefaults(article);
            ctx.knowledgeModalOpen = true;
        },

        closeKnowledgeModal(ctx) {
            ctx.knowledgeModalOpen = false;
            ctx.editingKnowledge = null;
            ctx.knowledgeModalTab = 'article';
            ctx.knowledgeForm = getKnowledgeFormDefaults();
            ctx.knowledgeMediaForm = getKnowledgeMediaFormDefaults();
        },

        async saveKnowledge(ctx) {
            try {
                const data = buildKnowledgePayload(ctx);

                if (ctx.editingKnowledge?.id) {
                    await apiUpdateKnowledge(ctx.editingKnowledge.id, data);
                    ctx.showToast('Статья обновлена', 'success');
                } else {
                    await apiCreateKnowledge(data);
                    ctx.showToast('Статья создана', 'success');
                }

                await ctx.loadKnowledge();
                ctx.closeKnowledgeModal();
            } catch (error) {
                console.error('Ошибка сохранения статьи:', error);
                ctx.showToast('Ошибка сохранения статьи: ' + (error.message || 'Неизвестная ошибка'), 'error');
            }
        },

        deleteKnowledge(ctx) {
            if (!ctx.editingKnowledge?.id) return;

            ctx.openConfirm(
                'Удалить статью?',
                'Вы уверены что хотите удалить эту статью? Это действие нельзя отменить.',
                async () => {
                    try {
                        await apiDeleteKnowledge(ctx.editingKnowledge.id);
                        ctx.showToast('Статья удалена', 'success');
                        await ctx.loadKnowledge();
                        ctx.closeKnowledgeModal();
                    } catch (error) {
                        console.error('Ошибка удаления статьи:', error);
                        ctx.showToast('Ошибка: ' + error.message, 'error');
                    }
                },
                { confirmText: 'Удалить', danger: true }
            );
        },

        getKnowledgeArticleType(_ctx, article) {
            return normalizeKnowledgeType(article?.type);
        },

        getKnowledgeArticleTitle(ctx, article) {
            const type = this.getKnowledgeArticleType(ctx, article);
            if (type === 'faq') return article?.question || 'FAQ';
            return article?.title || 'Без названия';
        },

        getKnowledgeArticleBody(ctx, article) {
            const type = this.getKnowledgeArticleType(ctx, article);
            if (type === 'faq') return article?.answer || 'Ответ не заполнен';
            return article?.content || article?.url || 'Контент пока не заполнен';
        },

        getKnowledgeArticlePreview(ctx, article) {
            const type = this.getKnowledgeArticleType(ctx, article);
            if (type === 'faq') return article?.answer || 'Описание отсутствует';
            return article?.content || article?.url || 'Описание отсутствует';
        },

        getKnowledgeTypeLabel(ctx, article) {
            const type = this.getKnowledgeArticleType(ctx, article);
            return ({ article: 'Статья', video: 'Видео', slides: 'Презентация', faq: 'FAQ' })[type] || 'Статья';
        },

        filterKnowledgeArticles(ctx, search = '', type = 'all') {
            const normalizedSearch = String(search || '').trim().toLowerCase();
            return (ctx.knowledgeArticles || []).filter(article => {
                const articleType = this.getKnowledgeArticleType(ctx, article);
                const matchesType = type === 'all' || (type === 'article' ? articleType === 'article' : articleType === type);
                if (!matchesType) return false;
                if (!normalizedSearch) return true;

                const haystack = [
                    article?.title,
                    article?.question,
                    article?.content,
                    article?.answer,
                    article?.url
                ].map(v => String(v || '').toLowerCase());

                return haystack.some(v => v.includes(normalizedSearch));
            });
        },

        countKnowledgeArticles(ctx, type = 'all', search = '') {
            return this.filterKnowledgeArticles(ctx, search, type).length;
        },

        findKnowledgeArticleById(ctx, articleId) {
            return (ctx.knowledgeArticles || []).find(article => String(article.id) === String(articleId)) || null;
        },

        getKnowledgeSelectedArticle(ctx, articleId) {
            if (!articleId) return null;
            return this.findKnowledgeArticleById(ctx, articleId);
        },

        ensureKnowledgePreviewId(ctx, previewId) {
            if (previewId) return previewId;
            return ctx.knowledgeArticles?.length ? ctx.knowledgeArticles[0].id : null;
        }
    };
})();
