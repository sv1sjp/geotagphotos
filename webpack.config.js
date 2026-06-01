const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const webpackRules = require('@nextcloud/webpack-vue-config/rules')

// Override RULE_TS: add transpileOnly so ts-loader does not chase cross-file
// imports inside vue-loader virtual modules (which have the wrong resolution
// context and cause "Can't resolve" errors).
const rules = Object.values(webpackRules).map(rule => {
	if (rule === webpackRules.RULE_TS) {
		return {
			...rule,
			use: [
				'babel-loader',
				{
					loader: 'ts-loader',
					options: {
						transpileOnly: true,
						appendTsSuffixTo: [/\.vue$/],
					},
				},
			],
		}
	}
	return rule
})

module.exports = {
	...webpackConfig,
	entry: {
		// Upstream filename template is "${appName}-[name].js" so entry "main"
		// produces "geotagphotos-main.js" — exactly what addScript() expects.
		main: path.join(__dirname, 'src', 'main.ts'),
	},
	output: {
		...webpackConfig.output,
		path: path.resolve(__dirname, 'js'),
	},
	module: {
		rules,
	},
}
