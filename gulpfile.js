const { src, dest, series } = require('gulp');
const gulpZip = require('gulp-zip').default;
const { deleteAsync } = require('del');

const pluginName = 'botisst-ai-chat-assistant';

function clean() {
    return deleteAsync(['dist']);
}

function copy() {
    return src([
        '**/*',
        '!node_modules/**',
        '!.git/**',
        '!.github/**',
        '!src/**',
        '!tests/**',
        '!dist/**',
        '!*.zip',
        '!package*.json',
        '!gulpfile.js',
        '!webpack.config.js',
        '!composer.lock',
        '!README.md',
        '!.gitignore',
        '!.distignore',
        '!.wordpress-org/**'
    ])
    .pipe(dest(`dist/${pluginName}`));
}

function buildZip() {
    return src(`dist/${pluginName}/**`, { base: 'dist' })
        .pipe(gulpZip(`${pluginName}.zip`))
        .pipe(dest('dist'));
}

exports.default = series(clean, copy, buildZip);