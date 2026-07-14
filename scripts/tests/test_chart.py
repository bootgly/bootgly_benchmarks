from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import chart


class AxisTest(unittest.TestCase):
    def test_additive_provenance_is_not_an_automatic_axis(self) -> None:
        parsed = [
            {
                "config": {
                    "source-identity-version": "raw-delta-manifest-v1",
                    "framework-sha": "a" * 40,
                    "framework-dirty": "true",
                    "framework-tracked-diff-sha256": "a" * 64,
                    "framework-untracked-manifest-sha256": "b" * 64,
                    "benchmarks-tracked-diff-sha256": "c" * 64,
                    "benchmarks-untracked-manifest-sha256": "d" * 64,
                    "batch-size": "1",
                }
            },
            {
                "config": {
                    "source-identity-version": "raw-delta-manifest-v2",
                    "framework-sha": "b" * 40,
                    "framework-dirty": "true",
                    "framework-tracked-diff-sha256": "e" * 64,
                    "framework-untracked-manifest-sha256": "f" * 64,
                    "benchmarks-tracked-diff-sha256": "0" * 64,
                    "benchmarks-untracked-manifest-sha256": "1" * 64,
                    "batch-size": "2",
                }
            },
        ]

        self.assertEqual(chart.detect_x_key(parsed, None), "batch-size")
        self.assertEqual(chart.detect_x_key(parsed, "framework-sha"), "framework-sha")
        self.assertEqual(
            chart.detect_x_key(parsed, "framework-tracked-diff-sha256"),
            "framework-tracked-diff-sha256",
        )


class ReportTest(unittest.TestCase):
    def test_shared_additive_provenance_is_rendered(self) -> None:
        with tempfile.TemporaryDirectory(prefix="bootgly-chart-") as directory:
            root = Path(directory)
            marks = [root / "one.bench.marks", root / "two.bench.marks"]
            for path in marks:
                path.write_text("")

            parsed = [
                {
                    "config": {
                        "source-identity-version": "raw-delta-manifest-v1",
                        "framework-version": "0.24.0-beta",
                        "framework-sha": "a" * 40,
                        "framework-dirty": "true",
                        "framework-tracked-diff-sha256": "d" * 64,
                        "framework-untracked-manifest-sha256": "e" * 64,
                        "benchmarks-sha": "c" * 40,
                        "benchmarks-dirty": "false",
                        "benchmarks-tracked-diff-sha256": chart.EMPTY_SHA256,
                        "benchmarks-untracked-manifest-sha256": chart.EMPTY_SHA256,
                        "server-workers": "1",
                    }
                },
                {
                    "config": {
                        "source-identity-version": "raw-delta-manifest-v1",
                        "framework-version": "0.24.0-beta",
                        "framework-sha": "b" * 40,
                        "framework-dirty": "true",
                        "framework-tracked-diff-sha256": "d" * 64,
                        "framework-untracked-manifest-sha256": "e" * 64,
                        "benchmarks-sha": "c" * 40,
                        "benchmarks-dirty": "false",
                        "benchmarks-tracked-diff-sha256": chart.EMPTY_SHA256,
                        "benchmarks-untracked-manifest-sha256": chart.EMPTY_SHA256,
                        "server-workers": "2",
                    }
                },
            ]
            report = root / "report.md"

            chart.write_report(
                report,
                "throughput.png",
                "ratio.png",
                "HTTP_Server_CLI",
                "benchmark",
                "Provenance test",
                "server-workers",
                [1.0, 2.0],
                ["Plaintext"],
                ["Bootgly"],
                {"Plaintext": {"Bootgly": [100, 200]}},
                "Bootgly",
                marks,
                parsed,
            )

            markdown = report.read_text()
            self.assertIn("**Source identity version** — `raw-delta-manifest-v1`", markdown)
            self.assertIn("**Framework dirty** — `true`", markdown)
            self.assertIn("**Framework tracked diff SHA-256** — `" + "d" * 64 + "`", markdown)
            self.assertIn("**Framework untracked manifest SHA-256** — `" + "e" * 64 + "`", markdown)
            self.assertIn("**Benchmarks SHA** — `" + "c" * 40 + "`", markdown)
            self.assertIn(
                "**Benchmarks tracked diff SHA-256** — `" + chart.EMPTY_SHA256 + "`",
                markdown,
            )
            self.assertIn(
                "**Benchmarks untracked manifest SHA-256** — `"
                + chart.EMPTY_SHA256
                + "`",
                markdown,
            )
            self.assertIn("**Mixed source provenance:**", markdown)
            self.assertIn("**Dirty source tree:** framework", markdown)
            self.assertNotIn("**Incomplete source provenance:**", markdown)

    def test_mixed_additive_provenance(self) -> None:
        with tempfile.TemporaryDirectory(prefix="bootgly-chart-") as directory:
            root = Path(directory)
            marks = [root / "one.bench.marks", root / "two.bench.marks"]
            for path in marks:
                path.write_text("")

            parsed = [
                {
                    "config": {
                        "source-identity-version": "raw-delta-manifest-v1",
                        "framework-tracked-diff-sha256": "a" * 64,
                        "framework-untracked-manifest-sha256": "c" * 64,
                        "benchmarks-tracked-diff-sha256": "e" * 64,
                        "benchmarks-untracked-manifest-sha256": "0" * 64,
                        "server-workers": "1",
                    }
                },
                {
                    "config": {
                        "source-identity-version": "raw-delta-manifest-v2",
                        "framework-tracked-diff-sha256": "b" * 64,
                        "framework-untracked-manifest-sha256": "d" * 64,
                        "benchmarks-tracked-diff-sha256": "f" * 64,
                        "benchmarks-untracked-manifest-sha256": "1" * 64,
                        "server-workers": "2",
                    }
                },
            ]
            report = root / "report.md"

            chart.write_report(
                report,
                "throughput.png",
                "ratio.png",
                "HTTP_Server_CLI",
                "benchmark",
                "Mixed provenance test",
                "server-workers",
                [1.0, 2.0],
                ["Plaintext"],
                ["Bootgly"],
                {"Plaintext": {"Bootgly": [100, 200]}},
                "Bootgly",
                marks,
                parsed,
            )

            markdown = report.read_text()
            self.assertIn(
                "`source-identity-version` = `raw-delta-manifest-v1`, `raw-delta-manifest-v2`",
                markdown,
            )
            for key, first, second in (
                ("framework-tracked-diff-sha256", "a", "b"),
                ("framework-untracked-manifest-sha256", "c", "d"),
                ("benchmarks-tracked-diff-sha256", "e", "f"),
                ("benchmarks-untracked-manifest-sha256", "0", "1"),
            ):
                with self.subTest(key=key):
                    self.assertIn(
                        f"`{key}` = `" + first * 64 + "`, `" + second * 64 + "`",
                        markdown,
                    )

    def test_incomplete_or_contradictory_provenance_is_not_grouped(self) -> None:
        with tempfile.TemporaryDirectory(prefix="bootgly-chart-") as directory:
            root = Path(directory)
            marks = [root / "one.bench.marks", root / "two.bench.marks"]
            for path in marks:
                path.write_text("")

            empty = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
            parsed = [
                {
                    "config": {
                        "source-identity-version": "raw-delta-manifest-v1",
                        "framework-sha": "a" * 40,
                        "framework-dirty": "false",
                        "framework-tracked-diff-sha256": "a" * 64,
                        "framework-untracked-manifest-sha256": empty,
                        "benchmarks-sha": "b" * 40,
                        "benchmarks-dirty": "false",
                        "benchmarks-tracked-diff-sha256": empty,
                        "benchmarks-untracked-manifest-sha256": empty,
                        "server-workers": "1",
                    }
                },
                {"config": {"server-workers": "2"}},
            ]
            report = root / "report.md"

            chart.write_report(
                report,
                "throughput.png",
                "ratio.png",
                "HTTP_Server_CLI",
                "benchmark",
                "Incomplete provenance test",
                "server-workers",
                [1.0, 2.0],
                ["Plaintext"],
                ["Bootgly"],
                {"Plaintext": {"Bootgly": [100, 200]}},
                "Bootgly",
                marks,
                parsed,
            )

            markdown = report.read_text()
            self.assertIn("**Incomplete source provenance:**", markdown)
            self.assertIn("`one.bench.marks` (incomplete framework tuple)", markdown)
            self.assertIn("`two.bench.marks` (unsupported or missing identity version", markdown)
            self.assertIn("Do not group these points as the same attributable source", markdown)


if __name__ == "__main__":
    unittest.main()
