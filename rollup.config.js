import resolve from '@rollup/plugin-node-resolve';
import terser from "@rollup/plugin-terser";

export default [
    // statistics.js
    {
        input: "resources/js/modules/index.js",
        output: [
            {
                name: "WebtreesStatistic",
                file: "resources/js/webtrees-statistics.js",
                format: "umd"
            }
        ],
        plugins: [
            resolve()
        ]
    },
    {
        input: "resources/js/modules/index.js",
        output: [
            {
                name: "WebtreesStatistic",
                file: "resources/js/webtrees-statistics.min.js",
                format: "umd"
            }
        ],
        plugins: [
            resolve(),
            terser({
                mangle: true,
                compress: true,
                module: true,
                output: {
                    comments: false
                }
            })
        ]
    }
];
