import fs from 'fs';
import { Resvg } from '@resvg/resvg-js';
import * as cheerio from 'cheerio';
import TextToSVG from 'text-to-svg';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const svgInPath = process.argv[2];
const svgOutPath = process.argv[3];
const pngOutPath = process.argv[4];
const pngScale = parseFloat(process.argv[5]) || 1;

const regularFontPath = path.join(__dirname, 'fonts', 'Lexend-Regular.ttf');
const boldFontPath = path.join(__dirname, 'fonts', 'Lexend-Bold.ttf');
const regularTextToSVG = TextToSVG.loadSync(regularFontPath);
const boldTextToSVG = TextToSVG.loadSync(boldFontPath);

const isBold = (weight) => ['bold', '600', '700', '800', '900'].includes(String(weight ?? '').trim());

const svgContentIn = fs.readFileSync(svgInPath, 'utf8');

// The PNG is the artifact both exporters embed (php-svg-lib mis-composes the
// icons' nested transforms, so PDF can't take the SVG directly). Rasterise the
// ORIGINAL SVG: resvg is a full renderer, so real <text> keeps its per-element
// font-weight (Regular vs Bold via fontFiles) and the feDropShadow node shadow
// survives — both match the live canvas. Only the data-URI @font-face <style>
// is dropped; fonts come from fontFiles. `pngScale` (argv[5]) supersamples for
// print sharpness, capped so oversized canvases can't produce runaway rasters.
if (pngOutPath) {
    const $png = cheerio.load(svgContentIn, { xmlMode: true });
    $png('style').remove();

    const MAX_DIM = 4096;
    const svgW = parseFloat($png('svg').first().attr('width')) || MAX_DIM;
    const svgH = parseFloat($png('svg').first().attr('height')) || MAX_DIM;
    const scale = Math.max(1, Math.min(pngScale, MAX_DIM / Math.max(svgW, svgH)));

    const opts = {
        fitTo: scale > 1 ? { mode: 'zoom', value: scale } : { mode: 'original' },
        font: {
            fontFiles: [regularFontPath, boldFontPath],
            loadSystemFonts: false,
            defaultFontFamily: 'Lexend'
        }
    };

    const resvg = new Resvg($png.xml(), opts);
    const pngData = resvg.render();
    const pngBuffer = pngData.asPng();
    fs.writeFileSync(pngOutPath, pngBuffer);
}

// The processed SVG is the exporters' no-PNG fallback (php-svg-lib ignores
// @font-face, so text is baked into Lexend vector paths — picked per element
// by font-weight — and the unsupported <style>/<defs>/filters are stripped).
const $ = cheerio.load(svgContentIn, { xmlMode: true });

$('text').each((i, el) => {
    const $el = $(el);
    const text = $el.text();
    const x = parseFloat($el.attr('x') || 0);
    const y = parseFloat($el.attr('y') || 0);
    const fontSize = parseFloat($el.attr('font-size') || 12);
    const anchor = $el.attr('text-anchor') || 'start';
    const textToSVG = isBold($el.attr('font-weight')) ? boldTextToSVG : regularTextToSVG;

    const options = {
        x: 0,
        y: 0,
        fontSize: fontSize,
        anchor: 'left top',
        attributes: { fill: $el.attr('fill') || '#5C625C' }
    };

    const metrics = textToSVG.getMetrics(text, options);
    const pathData = textToSVG.getD(text, options);

    let adjX = x;
    if (anchor === 'middle') adjX -= metrics.width / 2;
    else if (anchor === 'end') adjX -= metrics.width;

    const adjY = y - metrics.height * 0.75;

    const $path = $('<path></path>');
    $path.attr('d', pathData);
    $path.attr('fill', $el.attr('fill') || '#5C625C');
    $path.attr('transform', `translate(${adjX}, ${adjY})`);

    $el.replaceWith($path);
});

$('style, defs').remove();
$('[filter]').removeAttr('filter');

fs.writeFileSync(svgOutPath, $.xml());
