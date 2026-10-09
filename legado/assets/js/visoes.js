/*
 * Visões da árvore em arvore.php (como no FamilySearch), todas sobre a mesma API e o mesmo foco:
 *   paisagem      → árvore com setas ▲ ◀ ▶ ▼ ♥ (assets/js/arvore.js, ArvoreGenealogica.criar)
 *   descendencia  → lista hierárquica expansível; api/descendentes.php?id=X&geracoes=N sob demanda
 *   leque         → fan chart de ancestrais em SVG (d3 7.9.0); api/ancestrais.php?id=X&geracoes=N
 *
 * O coordenador (iniciar) guarda a pessoa em foco e a visão, mantém a URL em ?id=X&visao=Y
 * (pushState; o botão voltar restaura foco e visão) e compartilha entre as visões o painel de
 * resumo, a busca "Ir para pessoa" e a mensagem de status (criarPainel, configurarBusca e
 * criarStatus de arvore.js).
 *
 * Segurança: nada vindo da API vai para innerHTML — DOM com textContent (util.el) e SVG com
 * d3 .text()/.attr().
 */
(function () {
    'use strict';

    var AG = window.ArvoreGenealogica;
    if (!AG) return;
    var U = AG.util, el = U.el, icone = U.icone, sexo = U.sexo, textoData = U.textoData, chamarApi = U.chamarApi;
    var VISOES = ['paisagem', 'descendencia', 'leque'];
    var COR = { M: '#002776', F: '#B22222', D: '#6c757d' };
    var ROTULO_UNIAO = { casamento: 'Casamento', uniao_estavel: 'União estável', outro: 'Outro' };
    function noop() {}

    // ------------------------------------------------------------------ textos comuns
    /** Período de vida com as datas formatadas pela API ("c. 1824 – 22/02/1882"). */
    function periodo(p) {
        var n = textoData(p && p.nascimento), f = textoData(p && p.falecimento);
        if (!n && !f) return '';
        return ((n || '?') + ' – ' + f).trim();
    }
    /** Dica (title) com datas e locais completos. */
    function dica(p) {
        function linha(rot, ev) {
            if (!ev || (!ev.data && !ev.local)) return '';
            return rot + ': ' + [textoData(ev), ev.local || ''].filter(Boolean).join(', ');
        }
        return [p.nome, linha('Nascimento', p.nascimento), linha('Falecimento', p.falecimento)].filter(Boolean).join('\n');
    }
    function rotuloVinculo(tipo, papel) {
        if (tipo === 'adotivo') return 'Adotivo';
        if (tipo === 'padrasto') return papel === 'mae' ? 'Madrasta' : 'Padrasto';
        if (tipo === 'tutela') return 'Tutela';
        return '';
    }

    // ================================================================== coordenador
    /**
     * c: api, logado, focoInicial, visaoInicial, titulo, palco, painel, busca, status, seletor (links
     *    [data-visao]), ajudas ([data-arv-ajuda] com data-<visao>), legendas ([data-visao-so]),
     *    paisagem {raiz, grafico, conjuges, controles}, descendencia {raiz}, leque {raiz}
     */
    function iniciar(c) {
        var o = Object.assign({ api: 'api/' }, c);
        var estado = { foco: null, visao: null };
        var titulo = o.titulo || document.title; // nome do sistema (config.php), vem de arvore.php
        var seq = 0;
        var status = AG.criarStatus(o.status);
        var painel = AG.criarPainel({
            painel: o.painel, api: o.api, logado: o.logado,
            focar: function (id) { return ir(id, null); },
            focoAtual: function () { return estado.foco; }
        });
        var ctx = {
            api: o.api, logado: o.logado, painel: painel, status: status,
            focar: function (id) { return ir(String(id), null).catch(noop); },
            focoAtual: function () { return estado.foco; }
        };
        var visoes = {
            paisagem: criarPaisagem(ctx, o.paisagem),
            descendencia: criarDescendencia(ctx, o.descendencia),
            leque: criarLeque(ctx, o.leque)
        };
        var links = o.seletor ? Array.from(o.seletor.querySelectorAll('[data-visao]')) : [];

        function urlPara(id, visao) {
            var u = new URL(window.location.href);
            if (id) u.searchParams.set('id', id); else u.searchParams.delete('id');
            if (visao && visao !== 'paisagem') u.searchParams.set('visao', visao); else u.searchParams.delete('visao');
            return u.href;
        }
        function exibir(visao) {
            VISOES.forEach(function (v) {
                var ativa = v === visao;
                if (o[v] && o[v].raiz) o[v].raiz.hidden = !ativa;
                visoes[v].ativa = ativa;
            });
            links.forEach(function (a) {
                var ativa = a.getAttribute('data-visao') === visao;
                a.classList.toggle('ativa', ativa);
                if (ativa) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
            });
            Array.from(o.ajudas || []).forEach(function (e) {
                var t = e.getAttribute('data-' + visao);
                if (t !== null) e.textContent = t;
            });
            Array.from(o.legendas || []).forEach(function (e) {
                e.hidden = e.getAttribute('data-visao-so').split(' ').indexOf(visao) < 0;
            });
            if (o.palco) o.palco.setAttribute('data-visao', visao);
        }
        function atualizarLinks() {
            links.forEach(function (a) { a.href = urlPara(estado.foco, a.getAttribute('data-visao')); });
        }

        /**
         * Mostra a pessoa id na visão (id/visão nulos = mantém os atuais).
         * opts.historico: 'push' (padrão) | 'substituir' | 'nenhum' (vindo do botão voltar).
         */
        function ir(id, visao, opts) {
            opts = opts || {};
            id = String(id || estado.foco);
            visao = VISOES.indexOf(visao) >= 0 ? visao : (estado.visao || 'paisagem');
            var meu = ++seq;
            exibir(visao);
            return visoes[visao].mostrar(id).then(function (card) {
                if (meu !== seq) return false;            // outra navegação começou depois
                if (card === false) {                     // visão ocupada: volta ao estado anterior
                    if (estado.visao) exibir(estado.visao);
                    return false;
                }
                estado.foco = id;
                estado.visao = visao;
                if (opts.historico !== 'nenhum') {
                    var url = urlPara(id, visao);
                    if (opts.historico === 'substituir') history.replaceState({ arvId: id, visao: visao }, '', url);
                    else if (url !== window.location.href) history.pushState({ arvId: id, visao: visao }, '', url);
                }
                if (card && card.nome) document.title = card.nome + ' | ' + titulo;
                atualizarLinks();
                painel.renovar();
                return true;
            }, function (e) {
                if (meu === seq) {
                    if (estado.visao && estado.visao !== visao) exibir(estado.visao);
                    status(e.message, true, 6000);
                }
                throw e;
            });
        }

        links.forEach(function (a) {
            a.addEventListener('click', function (e) {
                if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0) return; // nova aba etc.
                e.preventDefault();
                var v = a.getAttribute('data-visao');
                if (!estado.foco || v === estado.visao) return;
                ir(estado.foco, v).catch(noop);
            });
        });
        if (o.busca) {
            AG.configurarBusca(o.busca, { api: o.api, status: status, escolher: function (p) { ir(String(p.id), null).catch(noop); } });
        }
        window.addEventListener('popstate', function () {
            var u = new URL(window.location.href);
            var id = u.searchParams.get('id'), v = u.searchParams.get('visao') || 'paisagem';
            if (VISOES.indexOf(v) < 0) v = 'paisagem';
            if (!id || !/^\d+$/.test(id) || (id === estado.foco && v === estado.visao)) return;
            ir(id, v, { historico: 'nenhum' }).catch(noop);
        });

        function raiz() { return chamarApi(o.api, 'raiz.php', {}).then(function (r) { return String(r.id); }); }
        var inicial = o.focoInicial ? String(o.focoInicial) : null;
        var visao0 = VISOES.indexOf(o.visaoInicial) >= 0 ? o.visaoInicial : 'paisagem';
        exibir(visao0);
        (inicial ? Promise.resolve(inicial) : raiz()).then(function (id) {
            return ir(id, visao0, { historico: 'substituir' }).catch(function (e) {
                if (e.status === 404 && inicial) {
                    // id da URL inexistente: abre a raiz padrão
                    return raiz().then(function (r) {
                        return ir(r, visao0, { historico: 'substituir' }).then(function () {
                            status('Pessoa ' + inicial + ' não encontrada. Mostrando a raiz da árvore.', true, 6000);
                        });
                    });
                }
                throw e;
            });
        }).catch(function (e) {
            if (e.status === 404) vazio(); else status(e.message, true); // nem a raiz existe: banco vazio
        });

        /** Banco sem ninguém: no lugar da árvore, o convite para cadastrar a primeira pessoa. */
        function vazio() {
            if (!o.palco) { status('Nenhuma pessoa cadastrada.', true); return; }
            o.palco.appendChild(el('div', { classe: 'arv-vazio' }, [
                icone('fas fa-sitemap'),
                el('div', { texto: 'Nenhuma pessoa cadastrada ainda.' }),
                o.logado
                    ? el('a', { classe: 'btn btn-primary', href: 'adicionar_pessoa.php' }, [icone('fas fa-user-plus me-1'), 'Adicionar a primeira pessoa'])
                    : el('a', { classe: 'btn btn-outline-secondary', href: 'login.php?voltar=adicionar_pessoa.php' }, ['Entrar para começar'])
            ]));
        }

        return {
            ir: ir, painel: painel, visoes: visoes,
            getFoco: function () { return estado.foco; },
            getVisao: function () { return estado.visao; }
        };
    }

    // ================================================================== Paisagem
    function criarPaisagem(ctx, e) {
        var arv = null;
        function garantir() {
            if (arv) return arv;
            if (!window.d3) throw new Error('Não foi possível carregar a biblioteca da árvore. Verifique a conexão.');
            // criada só quando a visão é exibida: o layout mede o contêiner (que não pode estar oculto)
            arv = AG.criar({
                grafico: e.grafico, seletorConjuges: e.conjuges, controles: e.controles,
                status: ctx.status, painelCompartilhado: ctx.painel, api: ctx.api, logado: ctx.logado,
                cliqueCard: 'painel', atualizarUrl: false, focarExterno: ctx.focar
            });
            window.arvoreGenealogica = arv; // depuração no console
            return arv;
        }
        return {
            mostrar: function (id) {
                var a;
                try { a = garantir(); } catch (err) { return Promise.reject(err); }
                if (a.getMainId() === id) {
                    a.redimensionar();
                    return Promise.resolve(a.getGrafo().pessoas.get(id) || {});
                }
                return a.focar(id, { historico: 'nenhum' }).then(function (ok) {
                    return ok ? (a.getGrafo().pessoas.get(id) || {}) : false;
                });
            },
            instancia: function () { return arv; }
        };
    }

    // ================================================================== Descendência (lista)
    function criarDescendencia(ctx, e) {
        var MAX_GER = 6;                 // limite de geracoes por chamada da API
        var grafo = new AG.Grafo();
        var carregados = new Set();      // pessoas cujos filhos (com os cônjuges deles) já vieram
        var abertos = new Set();         // caminhos abertos ("1/4/10"): a mesma pessoa pode aparecer 2x (implexo)
        var foco = null, nivelAberto = 1, ocupado = false;

        var titulo = el('span', { classe: 'arv-desc-titulo' });
        var bMais = el('button', { type: 'button', classe: 'btn btn-sm btn-outline-primary', title: 'Abrir mais uma geração', onclick: function () { abrirNivel(nivelAberto + 1); } },
            [icone('fas fa-angles-down'), el('span', { classe: 'arv-rot', texto: ' Mais uma geração' })]);
        var bRecolher = el('button', { type: 'button', classe: 'btn btn-sm btn-outline-secondary', title: 'Recolher (só os filhos)', onclick: recolher },
            [icone('fas fa-angles-up'), el('span', { classe: 'arv-rot', texto: ' Recolher' })]);
        var barra = el('div', { classe: 'arv-desc-barra' }, [titulo, el('div', { classe: 'arv-desc-acoes' }, [bMais, bRecolher])]);
        var lista = el('div', { classe: 'arv-desc-lista' });
        e.raiz.appendChild(el('div', { classe: 'arv-desc' }, [barra, lista]));

        function carregar(id, geracoes) {
            return chamarApi(ctx.api, 'descendentes.php', { id: id, geracoes: geracoes }).then(function (r) {
                grafo.mesclar(r);
                carregados.add(String(id));
                (r.niveis || []).forEach(function (nv) {
                    if (nv.geracao < geracoes) nv.pessoas.forEach(function (p) { carregados.add(String(p)); });
                });
                return r;
            });
        }
        function precisaBuscar(id) {
            var p = grafo.pessoas.get(id);
            return !!(p && p.tem_mais_descendentes && !carregados.has(id));
        }

        function mostrar(id) {
            if (id === foco && lista.firstChild) return Promise.resolve(grafo.pessoas.get(id));
            if (ocupado) return Promise.resolve(false);
            ocupado = true;
            ctx.status('Carregando…');
            var pronto = carregados.has(id) && grafo.pessoas.has(id) ? Promise.resolve() : carregar(id, 1);
            return pronto.then(function () {
                ctx.status('');
                foco = id;
                nivelAberto = 1;
                abertos = new Set([id]);
                render();
                lista.scrollTop = 0;
                return grafo.pessoas.get(id);
            }, function (err) { ctx.status(''); throw err; }).finally(function () { ocupado = false; });
        }

        function render(chaveFoco) {
            var topo = lista.scrollTop;
            var p = grafo.pessoas.get(foco);
            titulo.textContent = '';
            titulo.appendChild(document.createTextNode('Descendentes de '));
            titulo.appendChild(el('strong', { texto: p ? p.nome : '' }));
            bMais.disabled = nivelAberto >= MAX_GER;
            lista.textContent = '';
            var raiz = el('ul', { classe: 'arv-desc-arvore', role: 'tree', 'aria-label': 'Descendentes' });
            raiz.appendChild(noPessoa(foco, [], null));
            lista.appendChild(raiz);
            lista.scrollTop = topo;
            if (chaveFoco) {
                var b = lista.querySelector('[data-chave="' + chaveFoco + '"]');
                if (b) b.focus({ preventScroll: true });
            }
        }

        function chip(id, extra) {
            var p = grafo.pessoas.get(id), s = sexo(p), ehFoco = id === foco;
            var nome = el('button', { type: 'button', classe: 'arv-desc-nome', title: dica(p), onclick: function () { ctx.painel.abrir(id); } }, [
                el('span', { classe: 'arv-desc-avatar arv-cor-' + s }, [icone('fas ' + U.ICONE_SEXO[s])]), el('span', { texto: p.nome })
            ]);
            var partes = [nome];
            var datas = periodo(p);
            if (datas) partes.push(el('span', { classe: 'arv-desc-datas', texto: datas }));
            if (extra) partes.push(el('span', { classe: 'arv-desc-tag', texto: extra }));
            if (ehFoco) partes.push(el('span', { classe: 'arv-desc-tag arv-desc-emfoco', texto: 'Em foco' }));
            else partes.push(el('button', { type: 'button', classe: 'arv-desc-focar', title: 'Focar em ' + p.nome, 'aria-label': 'Focar em ' + p.nome, onclick: function () { ctx.focar(id); } }, [icone('fas fa-crosshairs')]));
            return el('div', { classe: 'arv-desc-pessoa' + (ehFoco ? ' foco' : '') }, partes);
        }

        function linhaConjuge(g) {
            var u = g.uniao, info = '';
            if (u) {
                var ini = u.data_inicio ? (u.data_inicio_texto || textoData({ data: u.data_inicio })) : '';
                var fim = u.data_fim ? (u.data_fim_texto || textoData({ data: u.data_fim })) : '';
                info = [ROTULO_UNIAO[u.tipo] || 'União', ini ? (ini + (u.local_inicio ? ', ' + u.local_inicio : '')) : (u.local_inicio || ''), fim ? 'até ' + fim : '']
                    .filter(Boolean).join(' · ');
            } else info = 'sem união registrada';
            var linha = el('div', { classe: 'arv-desc-conjuge' }, [el('span', { classe: 'arv-desc-coracao', title: 'Cônjuge' }, [icone('fas fa-heart')]), chip(g.conjuge)]);
            linha.appendChild(el('div', { classe: 'arv-desc-uniao', texto: info }));
            return linha;
        }

        /** <li> de uma pessoa da linhagem: linha, cônjuges e (aberto) filhos por união. */
        function noPessoa(id, caminho, vinculo) {
            var p = grafo.pessoas.get(id);
            var chave = caminho.concat(id).join('/');
            var repetido = caminho.indexOf(id) >= 0; // ciclo (dados gravados fora da aplicação)
            var familias = repetido ? [] : grafo.familias(id);
            var temFilhos = familias.some(function (g) { return g.filhos.length; });
            var expansivel = !repetido && (temFilhos || precisaBuscar(id));
            var aberto = expansivel && abertos.has(chave) && !precisaBuscar(id);

            var li = el('li', { classe: 'arv-desc-no', role: 'treeitem' });
            if (expansivel) li.setAttribute('aria-expanded', aberto ? 'true' : 'false');
            var toggle;
            if (expansivel) {
                toggle = el('button', {
                    type: 'button', classe: 'arv-desc-toggle', 'data-chave': chave,
                    title: (aberto ? 'Ocultar filhos de ' : 'Mostrar filhos de ') + p.nome,
                    'aria-label': (aberto ? 'Ocultar filhos de ' : 'Mostrar filhos de ') + p.nome,
                    onclick: function () { alternar(chave, id, toggle); }
                }, [icone('fas ' + (aberto ? 'fa-chevron-down' : 'fa-chevron-right'))]);
            } else toggle = el('span', { classe: 'arv-desc-toggle vazio', 'aria-hidden': 'true' });
            var extra = vinculo ? rotuloVinculo(vinculo.tipo, vinculo.papel) : '';
            li.appendChild(el('div', { classe: 'arv-desc-linha' }, [toggle, chip(id, extra ? '(' + extra + ')' : (repetido ? '(já listado acima)' : ''))]));

            var ulF = el('ul', { classe: 'arv-desc-familias', role: 'group' });
            familias.forEach(function (g) {
                var liF = el('li', { classe: 'arv-desc-familia' });
                if (g.conjuge) liF.appendChild(linhaConjuge(g));
                else if (aberto && g.filhos.length) liF.appendChild(el('div', { classe: 'arv-desc-semoutro', texto: 'Filhos sem o outro genitor registrado' }));
                if (aberto && g.filhos.length) {
                    var ulC = el('ul', { classe: 'arv-desc-filhos', role: 'group' });
                    g.filhos.forEach(function (f) { ulC.appendChild(noPessoa(f.id, caminho.concat(id), f)); });
                    liF.appendChild(ulC);
                } else if (aberto && g.conjuge) {
                    liF.appendChild(el('div', { classe: 'arv-desc-semoutro', texto: 'Sem filhos registrados com este cônjuge' }));
                }
                if (liF.firstChild) ulF.appendChild(liF);
            });
            if (ulF.firstChild) li.appendChild(ulF);
            return li;
        }

        function alternar(chave, id, botao) {
            if (abertos.has(chave)) { abertos.delete(chave); render(chave); return; }
            abertos.add(chave);
            if (!precisaBuscar(id)) { render(chave); return; }
            if (ocupado) { abertos.delete(chave); return; }
            ocupado = true;
            botao.classList.add('arv-girando');
            ctx.status('Carregando filhos…');
            carregar(id, 1).then(function () { ctx.status(''); render(chave); }, function (err) {
                abertos.delete(chave);
                botao.classList.remove('arv-girando');
                ctx.status(err.message, true, 6000);
            }).finally(function () { ocupado = false; });
        }

        /** Abre todas as gerações até n (uma chamada só: descendentes.php?id=foco&geracoes=n). */
        function abrirNivel(n) {
            n = Math.min(MAX_GER, n);
            if (!foco || ocupado || n <= nivelAberto) return;
            ocupado = true;
            ctx.status('Carregando…');
            carregar(foco, n).then(function () {
                ctx.status('');
                nivelAberto = n;
                (function abrir(id, caminho, nivel) {
                    if (nivel >= n || caminho.indexOf(id) >= 0) return;
                    abertos.add(caminho.concat(id).join('/'));
                    grafo.familias(id).forEach(function (g) {
                        g.filhos.forEach(function (f) { abrir(f.id, caminho.concat(id), nivel + 1); });
                    });
                })(foco, [], 0);
                render();
            }, function (err) { ctx.status(err.message, true, 6000); }).finally(function () { ocupado = false; });
        }
        function recolher() {
            if (!foco) return;
            nivelAberto = 1;
            abertos = new Set([foco]);
            render();
        }

        return { mostrar: mostrar, grafo: grafo };
    }

    // ================================================================== Leque (fan chart)
    function criarLeque(ctx, e) {
        var MIN_G = 2, MAX_G = 6;
        var R0 = 74;                                        // raio do círculo central
        var ESP = [0, 84, 84, 92, 128, 120, 112];           // espessura de cada anel (unidades do desenho)
        var FONTE = [15, 14, 13, 11.5, 12, 11, 10];         // fonte do nome por anel (0 = centro)
        var ABERTURA = Math.PI;                             // leque de 180°
        var grafo = new AG.Grafo();
        var pedidos = new Map();                            // foco -> gerações já buscadas
        var foco = null, geracoes = 4, girado = null, selecionado = null, tClique = null, uid = 0;
        var svg = null, mundo = null, zoom = null, ocupado = false, mult = 1;
        var medidor = document.createElement('canvas').getContext('2d');

        // ----- barra (gerações) e controles (zoom)
        var rotG = el('span', { classe: 'arv-leque-g', 'aria-live': 'polite' });
        var bMenos = el('button', { type: 'button', classe: 'btn btn-sm btn-outline-primary', title: 'Menos gerações', 'aria-label': 'Menos gerações', onclick: function () { mudarGeracoes(geracoes - 1); } }, [icone('fas fa-minus')]);
        var bMais = el('button', { type: 'button', classe: 'btn btn-sm btn-outline-primary', title: 'Mais gerações', 'aria-label': 'Mais gerações', onclick: function () { mudarGeracoes(geracoes + 1); } }, [icone('fas fa-plus')]);
        var barra = el('div', { classe: 'arv-leque-barra' }, [el('span', { classe: 'arv-rot-g', texto: 'Gerações' }), bMenos, rotG, bMais]);
        function botaoCtl(cmd, ic, tit) {
            return el('button', { type: 'button', classe: 'btn', title: tit, 'aria-label': tit, onclick: function () { comando(cmd); } }, [icone('fas ' + ic)]);
        }
        var controles = el('div', { classe: 'arv-controles' }, [
            botaoCtl('mais', 'fa-plus', 'Aproximar'), botaoCtl('menos', 'fa-minus', 'Afastar'),
            botaoCtl('ajustar', 'fa-expand', 'Mostrar o leque inteiro'), botaoCtl('girar', 'fa-arrows-rotate', 'Girar o leque')
        ]);
        var palco = el('div', { classe: 'arv-leque-svg' });
        e.raiz.appendChild(palco);
        e.raiz.appendChild(barra);
        e.raiz.appendChild(controles);
        atualizarBarra();

        function garantirSvg() {
            if (svg) return;
            if (!window.d3) throw new Error('Não foi possível carregar a biblioteca d3. Verifique a conexão.');
            svg = window.d3.select(palco).append('svg').attr('class', 'arv-leque').attr('role', 'group').attr('aria-label', 'Leque de ancestrais');
            mundo = svg.append('g');
            zoom = window.d3.zoom().scaleExtent([0.12, 8]).on('zoom', function (ev) { mundo.attr('transform', ev.transform); });
            svg.call(zoom).on('dblclick.zoom', null);
            svg.on('click', function (ev) { if (ev.target === svg.node()) selecionar(null); });
        }
        function atualizarBarra() {
            rotG.textContent = String(geracoes);
            bMenos.disabled = geracoes <= MIN_G;
            bMais.disabled = geracoes >= MAX_G;
        }

        function buscar(id, g) {
            if ((pedidos.get(id) || 0) >= g) return Promise.resolve();
            return chamarApi(ctx.api, 'ancestrais.php', { id: id, geracoes: g }).then(function (r) {
                grafo.mesclar(r);
                pedidos.set(id, g);
            });
        }

        function mostrar(id) {
            try { garantirSvg(); } catch (err) { return Promise.reject(err); }
            if (id === foco && mundo.node().firstChild) { redesenhar(); return Promise.resolve(grafo.pessoas.get(id)); }
            if (ocupado) return Promise.resolve(false);
            ocupado = true;
            ctx.status('Carregando…');
            return buscar(id, geracoes).then(function () {
                ctx.status('');
                foco = id;
                selecionado = null;
                desenhar(true);
                ajustar(0);
                return grafo.pessoas.get(id);
            }, function (err) { ctx.status(''); throw err; }).finally(function () { ocupado = false; });
        }

        function mudarGeracoes(n) {
            n = Math.max(MIN_G, Math.min(MAX_G, n));
            if (n === geracoes || !foco || ocupado) return;
            var antes = geracoes;
            geracoes = n;
            atualizarBarra();
            ocupado = true;
            ctx.status('Carregando…');
            buscar(foco, n).then(function () { ctx.status(''); desenhar(false); ajustar(450); }, function (err) {
                geracoes = antes;
                atualizarBarra();
                ctx.status(err.message, true, 6000);
            }).finally(function () { ocupado = false; });
        }

        // ----- geometria
        function eixo() {
            // abre para cima em telas largas; para a direita em telas em retrato (celular)
            if (girado !== null) return girado;
            var r = palco.getBoundingClientRect();
            return r.height > r.width * 0.9 ? 0 : -Math.PI / 2;
        }
        function raios(g) {
            var r = R0 + 4;
            for (var i = 1; i < g; i++) r += ESP[i];
            return [r, r + ESP[g]];
        }
        /** Posições Ahnentafel: anel g tem 2^g posições; pai de k = 2k, mãe = 2k+1 no anel seguinte. */
        function posicoes() {
            var aneis = [[{ id: foco, g: 0, k: 0 }]];
            for (var g = 1; g <= geracoes; g++) {
                var anel = [];
                for (var k = 0; k < (1 << g); k++) {
                    var filho = aneis[g - 1][k >> 1].id, id = null;
                    if (filho) { var pm = grafo.paisDe(filho); id = k % 2 === 0 ? pm.pai : pm.mae; }
                    anel.push({ id: id, g: g, k: k, semFilho: !filho });
                }
                aneis.push(anel);
            }
            return aneis;
        }

        // ----- texto: medir, abreviar, quebrar
        function fonte(px, peso) { return (peso || 600) + ' ' + px + 'px Inter, "Segoe UI", Arial, sans-serif'; }
        function largura(t, px, peso) { medidor.font = fonte(px, peso); return medidor.measureText(t).width; }
        function inicial(w) { var m = /[A-Za-zÀ-ÿ]/.exec(w); return m ? m[0].toUpperCase() + '.' : ''; }
        function abreviar(nome, max, px, peso) {
            nome = String(nome || '').replace(/\s+/g, ' ').trim();
            if (largura(nome, px, peso) <= max) return nome;
            var t = nome.split(' '), cands = [];
            if (t.length > 2) cands.push(t[0] + ' ' + t.slice(1, -1).map(inicial).join(' ') + ' ' + t[t.length - 1]);
            if (t.length > 1) {
                cands.push(t[0] + ' ' + t[t.length - 1]);
                cands.push(t[0] + ' ' + inicial(t[t.length - 1]));
                cands.push(t[0]);
            }
            for (var i = 0; i < cands.length; i++) if (largura(cands[i], px, peso) <= max) return cands[i];
            var s = t[0];
            while (s.length > 1 && largura(s + '…', px, peso) > max) s = s.slice(0, -1);
            return s.length > 1 ? s + '…' : '';
        }
        /** Até maxLinhas linhas; se o nome inteiro não couber, tenta com iniciais do meio, depois prenome + sobrenome. */
        function quebrar(nome, max, px, maxLinhas) {
            nome = String(nome || '').replace(/\s+/g, ' ').trim();
            if (maxLinhas <= 1 || largura(nome, px) <= max) return [abreviar(nome, max, px)];
            function envolver(texto) {
                var linhas = [], atual = '';
                texto.split(' ').forEach(function (w) {
                    var tent = atual ? atual + ' ' + w : w;
                    if (!atual || largura(tent, px) <= max) atual = tent; else { linhas.push(atual); atual = w; }
                });
                if (atual) linhas.push(atual);
                return linhas;
            }
            var t = nome.split(' '), cands = [nome];
            if (t.length > 2) cands.push(t[0] + ' ' + t.slice(1, -1).map(inicial).join(' ') + ' ' + t[t.length - 1]);
            if (t.length > 1) cands.push(t[0] + ' ' + t[t.length - 1]);
            for (var i = 0; i < cands.length; i++) {
                var l = envolver(cands[i]);
                if (l.length <= maxLinhas && l.every(function (x) { return largura(x, px) <= max; })) return l;
            }
            return [abreviar(nome, max, px)];
        }

        function arcoD(r, de, ate, sentido) {
            var x0 = r * Math.cos(de), y0 = r * Math.sin(de), x1 = r * Math.cos(ate), y1 = r * Math.sin(ate);
            return 'M' + x0.toFixed(2) + ',' + y0.toFixed(2) + ' A' + r + ',' + r + ' 0 ' + (Math.abs(ate - de) > Math.PI ? 1 : 0) + ' ' + sentido + ' ' + x1.toFixed(2) + ',' + y1.toFixed(2);
        }

        // ----- desenho
        function desenhar(entrada) {
            var d3 = window.d3;
            var E = eixo();
            mult = palco.getBoundingClientRect().width < 640 ? 1.3 : 1; // celular: fontes maiores (o leque fica menor na tela)
            mundo.selectAll('*').remove();
            if (entrada) { mundo.classed('lq-entrar', false); mundo.node().getBoundingClientRect(); mundo.classed('lq-entrar', true); } // reinicia a animação
            var defs = mundo.append('defs');
            var arco = d3.arc();
            var aneis = posicoes();
            uid++;

            for (var g = geracoes; g >= 1; g--) {
                var rr = raios(g), ri = rr[0], ro = rr[1], passo = ABERTURA / (1 << g);
                aneis[g].forEach(function (s) {
                    var a0 = E - ABERTURA / 2 + s.k * passo, a1 = a0 + passo, meio = (a0 + a1) / 2;
                    var grupo = mundo.append('g').attr('class', 'lq-pos ' + (s.id ? 'lq-pessoa' : 'lq-vazio' + (s.semFilho ? ' lq-vazio-fundo' : '')));
                    grupo.append('path').attr('class', 'lq-fatia')
                        .attr('d', arco({ innerRadius: ri, outerRadius: ro, startAngle: a0 + Math.PI / 2, endAngle: a1 + Math.PI / 2 }))
                        .attr('fill', s.id ? COR[sexo(grafo.pessoas.get(s.id))] : null);
                    if (!s.id) {
                        grupo.append('title').text(s.semFilho ? '' : (s.k % 2 === 0 ? 'Pai não registrado' : 'Mãe não registrada'));
                        return;
                    }
                    var p = grafo.pessoas.get(s.id);
                    interativo(grupo, s.id, p);
                    if (g <= 3) textoCurvo(grupo, defs, p, g, s.k, ri, ro, a0, a1, meio);
                    else textoRadial(grupo, p, g, ri, ro, passo, meio);
                    if (g === geracoes) {
                        var pm = grafo.paisDe(s.id);
                        if (pm.pai || pm.mae || p.tem_mais_ancestrais) marcarMais(s.id, p, ro, meio);
                    }
                });
            }
            centro(grafo.pessoas.get(foco));
            selecionar(selecionado);
        }

        function interativo(grupo, id, p) {
            grupo.attr('tabindex', 0).attr('role', 'button').attr('data-id', id)
                .attr('aria-label', p.nome + (periodo(p) ? ', ' + periodo(p) : '') + '. Enter abre o resumo; F foca no leque.')
                .on('click', function (ev) {
                    ev.stopPropagation();
                    clearTimeout(tClique);
                    tClique = setTimeout(function () { selecionar(id); ctx.painel.abrir(id); }, 230);
                })
                .on('dblclick', function (ev) {
                    ev.preventDefault();
                    ev.stopPropagation();
                    clearTimeout(tClique);
                    if (id !== foco) ctx.focar(id);
                })
                .on('keydown', function (ev) {
                    if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); selecionar(id); ctx.painel.abrir(id); }
                    else if ((ev.key === 'f' || ev.key === 'F') && id !== foco) { ev.preventDefault(); ctx.focar(id); }
                });
            grupo.append('title').text(dica(p));
        }

        function textoCurvo(grupo, defs, p, g, k, ri, ro, a0, a1, meio) {
            var rm = (ri + ro) / 2, px = FONTE[g] * mult, pxA = px - 2.5 * mult;
            var baixo = Math.sin(meio) > 1e-6;              // metade de baixo: texto invertido no arco
            var anos = p.anos || '';
            var linhas = anos
                ? [{ t: p.nome, r: baixo ? rm - 2 : rm + 2, px: px, peso: 600 }, { t: anos, r: baixo ? rm + pxA + 2 : rm - pxA - 2, px: pxA, peso: 400 }]
                : [{ t: p.nome, r: baixo ? rm - px * 0.35 : rm + px * 0.35, px: px, peso: 600 }];
            linhas.forEach(function (l, i) {
                var r = l.r;
                var max = r * (a1 - a0) - 12;
                var txt = i === 0 ? abreviar(l.t, max, l.px, l.peso) : (largura(l.t, l.px, l.peso) <= max ? l.t : abreviar(String(l.t).replace(/\s+/g, ''), max, l.px, l.peso));
                if (!txt) return;
                var pid = 'lq' + uid + '-' + g + '-' + k + '-' + i;
                defs.append('path').attr('id', pid).attr('d', baixo ? arcoD(r, a1, a0, 0) : arcoD(r, a0, a1, 1));
                grupo.append('text').attr('class', i === 0 ? 'lq-nome' : 'lq-anos').style('font-size', l.px + 'px')
                    .append('textPath').attr('href', '#' + pid).attr('startOffset', '50%').attr('text-anchor', 'middle').text(txt);
            });
        }

        function textoRadial(grupo, p, g, ri, ro, passo, meio) {
            var graus = meio * 180 / Math.PI, virar = Math.cos(meio) < -1e-6;
            var compr = ro - ri - 14;                                   // comprimento ao longo do raio
            var alto = (ri + (ro - ri) * 0.25) * passo - 4;             // espaço na largura da fatia
            var px = Math.min(FONTE[g] * mult, Math.max(7, alto * 0.42)), pxA = Math.max(6.5, px - 1.5);
            var anos = p.anos || '';
            var lh = px * 1.12, cabemLinhas = Math.max(1, Math.floor((alto - (anos ? pxA * 1.1 : 0)) / lh));
            if (anos && alto < px * 1.12 + pxA * 1.05) { anos = ''; cabemLinhas = Math.max(1, Math.floor(alto / lh)); }
            var nomes = quebrar(p.nome, compr, px, Math.min(2, cabemLinhas));
            var linhas = nomes.map(function (t) { return { t: t, px: px, cls: 'lq-nome' }; });
            if (anos) linhas.push({ t: largura(anos, pxA, 400) <= compr ? anos : abreviar(anos.replace(/\s+/g, ''), compr, pxA, 400), px: pxA, cls: 'lq-anos' });
            var total = linhas.reduce(function (s, l) { return s + l.px * 1.12; }, 0);
            var y = -total / 2;
            var t = grupo.append('g').attr('transform', 'rotate(' + graus.toFixed(3) + ') translate(' + ((ri + ro) / 2).toFixed(2) + ',0)' + (virar ? ' rotate(180)' : ''));
            linhas.forEach(function (l) {
                y += l.px * 1.12;
                if (!l.t) return;
                t.append('text').attr('class', l.cls).attr('text-anchor', 'middle').attr('y', (y - l.px * 0.25).toFixed(2))
                    .style('font-size', l.px + 'px').text(l.t);
            });
        }

        function marcarMais(id, p, ro, meio) {
            var x = (ro + 9) * Math.cos(meio), y = (ro + 9) * Math.sin(meio);
            var podeMais = geracoes < MAX_G;
            var dicaMais = podeMais ? 'Há mais ancestrais de ' + p.nome + ': mostrar mais uma geração' : 'Há mais ancestrais de ' + p.nome + ': focar nesta pessoa';
            var m = mundo.append('g').attr('class', 'lq-mais').attr('transform', 'translate(' + x.toFixed(2) + ',' + y.toFixed(2) + ')')
                .attr('tabindex', 0).attr('role', 'button').attr('aria-label', dicaMais)
                .on('click', function (ev) { ev.stopPropagation(); if (podeMais) mudarGeracoes(geracoes + 1); else ctx.focar(id); })
                .on('keydown', function (ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); if (podeMais) mudarGeracoes(geracoes + 1); else ctx.focar(id); } });
            m.append('circle').attr('r', 7);
            m.append('title').text(dicaMais);
        }

        function centro(p) {
            if (!p) return;
            var s = sexo(p);
            var grupo = mundo.append('g').attr('class', 'lq-pos lq-pessoa lq-centro');
            grupo.append('circle').attr('class', 'lq-fatia').attr('r', R0).attr('fill', COR[s]);
            interativo(grupo, foco, p);
            var px = FONTE[0] * mult, pxA = 11.5 * mult, max = R0 * 1.6;
            var nomes = quebrar(p.nome, max, px, 3);
            var anos = p.anos || '';
            var total = nomes.length * px * 1.15 + (anos ? pxA * 1.3 : 0);
            var y = -total / 2;
            nomes.forEach(function (t) {
                y += px * 1.15;
                grupo.append('text').attr('class', 'lq-nome').attr('text-anchor', 'middle').attr('y', (y - px * 0.25).toFixed(2)).style('font-size', px + 'px').text(t);
            });
            if (anos) {
                y += pxA * 1.3;
                grupo.append('text').attr('class', 'lq-anos').attr('text-anchor', 'middle').attr('y', (y - pxA * 0.2).toFixed(2)).style('font-size', pxA + 'px')
                    .text(largura(anos, pxA, 400) <= max ? anos : abreviar(anos.replace(/\s+/g, ''), max, pxA, 400));
            }
        }

        function selecionar(id) {
            selecionado = id;
            if (!mundo) return;
            mundo.selectAll('.lq-pessoa').classed('lq-sel', function () { return id !== null && this.getAttribute('data-id') === id; });
        }

        // ----- zoom
        function ajustar(tempo) {
            if (!svg) return;
            var r = palco.getBoundingClientRect();
            if (!r.width || !r.height || !mundo.node().firstChild) return;
            var bb = mundo.node().getBBox();
            var estreito = r.width < 640;
            var topo = 52, dir = estreito ? 8 : 64, m = 12;
            var W = Math.max(60, r.width - dir - m * 2), H = Math.max(60, r.height - topo - m * 2);
            var k = Math.min(W / bb.width, H / bb.height, 2.2);
            var tx = m + (W - bb.width * k) / 2 - bb.x * k, ty = topo + m + (H - bb.height * k) / 2 - bb.y * k;
            var t = window.d3.zoomIdentity.translate(tx, ty).scale(k);
            if (tempo) svg.transition().duration(tempo).call(zoom.transform, t); else svg.call(zoom.transform, t);
        }
        function comando(cmd) {
            if (!svg || !foco) return;
            if (cmd === 'mais') svg.transition().duration(250).call(zoom.scaleBy, 1.3);
            else if (cmd === 'menos') svg.transition().duration(250).call(zoom.scaleBy, 1 / 1.3);
            else if (cmd === 'ajustar') ajustar(400);
            else if (cmd === 'girar') { girado = eixo() === 0 ? -Math.PI / 2 : 0; desenhar(false); ajustar(400); }
        }
        function redesenhar() {
            if (!svg || !foco || !palco.getBoundingClientRect().width) return;
            desenhar(false);
            ajustar(0);
        }
        var tRes = null;
        window.addEventListener('resize', function () {
            clearTimeout(tRes);
            tRes = setTimeout(function () { if (!e.raiz.hidden) redesenhar(); }, 200);
        });

        return { mostrar: mostrar, grafo: grafo, comando: comando, mudarGeracoes: mudarGeracoes, getGeracoes: function () { return geracoes; } };
    }

    window.ArvoreVisoes = { iniciar: iniciar };
})();
