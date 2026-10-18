const Encore = require(
        '@symfony/webpack-encore');

if (!Encore.isRuntimeEnvironmentConfigured()) {
    Encore.configureRuntimeEnvironment(
            process.env.NODE_ENV ||
            'dev');
}

Encore
        .addEntry(
                'share',
                './resources/share.js')
        .addEntry(
                'app',
                './resources/app.js')
        .addEntry(
                'media',
                './resources/media.js')
        .addEntry(
                'editor',
                './resources/editor.js')
        .addEntry(
                'bulletin',
                './resources/bulletin.js')
        .cleanupOutputBeforeBuild()
        .disableSingleRuntimeChunk()
        .enableSassLoader()
        .enableSourceMaps(
                !Encore.isProduction())
        .enableVersioning(
                true)
        .enableReactPreset()
        .copyFiles(
                [{
                    from: 'resources/images/',
                    to: './images/[path][name].[ext]'
                }])
        .setOutputPath(
                '../../web/app/themes/theme/public')
        .setPublicPath(
                '/app/themes/theme/public');

if (Encore.isProduction()) {
    Encore
            .configureBabel(
                    null,
                    {
                        includeNodeModules: ['jquery',
                            'bootstrap']
                    })
            .configureBabelPresetEnv(
                    (config) => {
                        config.useBuiltIns = 'usage';
                        config.corejs = 2;
                        config.forceAllTransforms = true;
                    });
}

module.exports = Encore.getWebpackConfig();
