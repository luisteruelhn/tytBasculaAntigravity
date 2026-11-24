/**
 * Generador Ligero Offline de Código QR en SVG (100% nativo, sin internet ni CDNs externos)
 * Basado en especificación ISO/IEC 18004 (Byte mode, Nivel L, versiones 1-10).
 */
(function () {
    'use strict';

    // Tablas GF(256) para Reed-Solomon (polinomio 0x11d)
    const EXP = new Uint8Array(512);
    const LOG = new Uint8Array(256);
    (function initGF() {
        let x = 1;
        for (let i = 0; i < 255; i++) {
            EXP[i] = x;
            LOG[x] = i;
            x <<= 1;
            if (x & 0x100) x ^= 0x11d;
        }
        for (let i = 255; i < 512; i++) EXP[i] = EXP[i - 255];
    })();

    function gfMul(a, b) {
        return (a === 0 || b === 0) ? 0 : EXP[LOG[a] + LOG[b]];
    }

    function rsGeneratorPoly(degree) {
        let poly = [1];
        for (let i = 0; i < degree; i++) {
            const next = new Array(poly.length + 1).fill(0);
            const root = EXP[i];
            for (let j = 0; j < poly.length; j++) {
                next[j] ^= poly[j];
                next[j + 1] ^= gfMul(poly[j], root);
            }
            poly = next;
        }
        return poly;
    }

    function rsRemainder(data, ecLen) {
        const gen = rsGeneratorPoly(ecLen);
        const rem = new Uint8Array(ecLen);
        for (let i = 0; i < data.length; i++) {
            const factor = data[i] ^ rem[0];
            rem.copyWithin(0, 1);
            rem[ecLen - 1] = 0;
            if (factor !== 0) {
                for (let j = 0; j < ecLen; j++) {
                    rem[j] ^= gfMul(gen[j + 1], factor);
                }
            }
        }
        return rem;
    }

    // Parámetros por versión 1..10 en Nivel L: [totalDataCodewords, ecCodewordsPerBlock, numBlocks]
    const VERSION_PARAMS_L = [
        null,
        [19, 7, 1],
        [34, 10, 1],
        [55, 15, 1],
        [80, 20, 1],
        [108, 26, 1],
        [136, 18, 2],
        [156, 20, 2],
        [194, 24, 2],
        [232, 30, 2],
        [274, 18, 4]
    ];

    const ALIGNMENT_POS = [
        null, [], [6, 18], [6, 22], [6, 26], [6, 30],
        [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50]
    ];

    function encodeBytes(text) {
        return new TextEncoder().encode(text);
    }

    function buildCodewords(bytes) {
        let ver = 1;
        while (ver <= 10) {
            const cap = VERSION_PARAMS_L[ver][0];
            const headerBits = 4 + (ver <= 9 ? 8 : 16);
            if (Math.ceil((headerBits + bytes.length * 8) / 8) <= cap) break;
            ver++;
        }
        if (ver > 10) throw new Error('Texto demasiado largo para QR local');

        const [totalData, ecLen, numBlocks] = VERSION_PARAMS_L[ver];
        const bits = [];
        const pushBits = (val, len) => {
            for (let i = len - 1; i >= 0; i--) bits.push((val >>> i) & 1);
        };

        pushBits(0b0100, 4); // Modo Byte
        pushBits(bytes.length, ver <= 9 ? 8 : 16);
        for (let i = 0; i < bytes.length; i++) pushBits(bytes[i], 8);

        const maxBits = totalData * 8;
        pushBits(0, Math.min(4, maxBits - bits.length));
        while (bits.length % 8 !== 0) bits.push(0);

        const dataBytes = new Uint8Array(totalData);
        for (let i = 0; i < bits.length / 8; i++) {
            let b = 0;
            for (let j = 0; j < 8; j++) b = (b << 1) | bits[i * 8 + j];
            dataBytes[i] = b;
        }
        let padToggle = true;
        for (let i = bits.length / 8; i < totalData; i++) {
            dataBytes[i] = padToggle ? 0xec : 0x11;
            padToggle = !padToggle;
        }

        // Dividir en bloques e intercalar
        const shortBlockLen = Math.floor(totalData / numBlocks);
        const numLongBlocks = totalData % numBlocks;
        const dataBlocks = [];
        const ecBlocks = [];
        let offset = 0;
        for (let b = 0; b < numBlocks; b++) {
            const len = shortBlockLen + (b >= numBlocks - numLongBlocks ? 1 : 0);
            const slice = dataBytes.slice(offset, offset + len);
            offset += len;
            dataBlocks.push(slice);
            ecBlocks.push(rsRemainder(slice, ecLen));
        }

        const finalBytes = [];
        const maxBlockLen = shortBlockLen + (numLongBlocks > 0 ? 1 : 0);
        for (let i = 0; i < maxBlockLen; i++) {
            for (let b = 0; b < numBlocks; b++) {
                if (i < dataBlocks[b].length) finalBytes.push(dataBlocks[b][i]);
            }
        }
        for (let i = 0; i < ecLen; i++) {
            for (let b = 0; b < numBlocks; b++) {
                finalBytes.push(ecBlocks[b][i]);
            }
        }

        return { ver, finalBytes };
    }

    function createMatrix(ver, finalBytes) {
        const size = ver * 4 + 17;
        const modules = Array.from({ length: size }, () => new Array(size).fill(false));
        const isFunc = Array.from({ length: size }, () => new Array(size).fill(false));

        const setFunc = (r, c, dark) => {
            if (r >= 0 && r < size && c >= 0 && c < size) {
                modules[r][c] = dark;
                isFunc[r][c] = true;
            }
        };

        const drawFinder = (r0, c0) => {
            for (let dy = -1; dy <= 7; dy++) {
                for (let dx = -1; dx <= 7; dx++) {
                    const dist = Math.max(Math.abs(dx - 3), Math.abs(dy - 3));
                    setFunc(r0 + dy, c0 + dx, dist !== 2 && dist !== 4);
                }
            }
        };

        drawFinder(0, 0);
        drawFinder(0, size - 7);
        drawFinder(size - 7, 0);

        // Timing patterns
        for (let i = 8; i < size - 8; i++) {
            setFunc(6, i, i % 2 === 0);
            setFunc(i, 6, i % 2 === 0);
        }

        // Alignment patterns
        const pos = ALIGNMENT_POS[ver];
        for (let i = 0; i < pos.length; i++) {
            for (let j = 0; j < pos.length; j++) {
                if ((i === 0 && j === 0) || (i === 0 && j === pos.length - 1) || (i === pos.length - 1 && j === 0)) continue;
                const cy = pos[i], cx = pos[j];
                for (let dy = -2; dy <= 2; dy++) {
                    for (let dx = -2; dx <= 2; dx++) {
                        setFunc(cy + dy, cx + dx, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
                    }
                }
            }
        }

        // Reservar bits de formato + módulo oscuro fijo
        for (let i = 0; i < 9; i++) {
            if (i !== 6) {
                isFunc[8][i] = true;
                isFunc[i][8] = true;
            }
        }
        for (let i = 0; i < 8; i++) {
            isFunc[8][size - 1 - i] = true;
            isFunc[size - 1 - i][8] = true;
        }
        setFunc(size - 8, 8, true);

        // Colocar bits de datos en zigzag
        let bitIdx = 0;
        const totalBits = finalBytes.length * 8;
        for (let right = size - 1; right >= 1; right -= 2) {
            if (right === 6) right = 5;
            for (let vert = 0; vert < size; vert++) {
                for (let j = 0; j < 2; j++) {
                    const x = right - j;
                    const upward = ((right + 1) & 2) === 0;
                    const y = upward ? size - 1 - vert : vert;
                    if (!isFunc[y][x] && bitIdx < totalBits) {
                        const dark = ((finalBytes[bitIdx >>> 3] >>> (7 - (bitIdx & 7))) & 1) !== 0;
                        modules[y][x] = dark;
                        bitIdx++;
                    }
                }
            }
        }

        // Aplicar máscara 0: (r + c) % 2 === 0
        for (let r = 0; r < size; r++) {
            for (let c = 0; c < size; c++) {
                if (!isFunc[r][c] && (r + c) % 2 === 0) {
                    modules[r][c] = !modules[r][c];
                }
            }
        }

        // Escribir bits de formato para Nivel L (01) + Máscara 0 (000) -> BCH + XOR 0x5412 = 0x77c4
        const formatBits = 0x77c4;
        const getBit = (v, i) => ((v >>> i) & 1) !== 0;
        for (let i = 0; i <= 5; i++) modules[8][i] = getBit(formatBits, i);
        modules[8][7] = getBit(formatBits, 6);
        modules[8][8] = getBit(formatBits, 7);
        modules[7][8] = getBit(formatBits, 8);
        for (let i = 9; i < 15; i++) modules[14 - i][8] = getBit(formatBits, i);

        for (let i = 0; i < 8; i++) modules[8][size - 1 - i] = getBit(formatBits, i);
        for (let i = 8; i < 15; i++) modules[size - 15 + i][8] = getBit(formatBits, i);

        return modules;
    }

    window.generarQrSvg = function (texto, margin = 3) {
        const bytes = encodeBytes(String(texto || ''));
        const { ver, finalBytes } = buildCodewords(bytes);
        const matrix = createMatrix(ver, finalBytes);
        const n = matrix.length;
        const total = n + margin * 2;
        let path = '';
        for (let y = 0; y < n; y++) {
            for (let x = 0; x < n; x++) {
                if (matrix[y][x]) {
                    path += `M${x + margin},${y + margin}h1v1h-1z`;
                }
            }
        }
        return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${total} ${total}" shape-rendering="crispEdges" style="width:100%;height:100%;display:block;border-radius:8px;background:#ffffff;"><rect width="100%" height="100%" fill="#ffffff"/><path d="${path}" fill="#0f172a"/></svg>`;
    };
})();
