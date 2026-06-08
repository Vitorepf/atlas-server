// swift-tools-version:5.9
// Atlas Mac Native — Swift Package for Apple-only Atlas AAEOS blocks.

import PackageDescription

let package = Package(
    name: "AtlasMacNative",
    platforms: [
        .macOS(.v14)
    ],
    products: [
        .library(name: "AtlasMacNative", targets: ["AtlasMacNative"])
    ],
    dependencies: [],
    targets: [
        .target(
            name: "AtlasMacNative",
            path: "Sources/AtlasMacNative"
        ),
        .testTarget(
            name: "AtlasMacNativeTests",
            dependencies: ["AtlasMacNative"],
            path: "Tests/AtlasMacNativeTests"
        )
    ]
)
