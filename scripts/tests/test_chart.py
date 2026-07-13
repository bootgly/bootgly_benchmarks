from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import chart


class AxisTest(unittest.TestCase):
    def test(self) -> None:
        parsed = [
            {
                "config": {
                    "framework-sha": "a" * 40,
                    "framework-dirty": "false",
                    "server-workers": "1",
                }
            },
            {
                "config": {
                    "framework-sha": "b" * 40,
                    "framework-dirty": "true",
                    "server-workers": "2",
                }
            },
        ]

        self.assertEqual(chart.detect_x_key(parsed, None), "server-workers")
        self.assertEqual(chart.detect_x_key(parsed, "framework-sha"), "framework-sha")

class ReportTest(unittest.TestCase):
    def test(self) -> None:
        with tempfile.TemporaryDirectory(prefix="bootgly-chart-") as directory:
            root = Path(directory)
            marks = [root / "one.bench.marks", root / "two.bench.marks"]
            for path in marks:
                path.write_text("")

            parsed = [
                {
                    "config": {
                        "framework-version": "0.24.0-beta",
                        "framework-sha": "a" * 40,
                        "framework-dirty": "true",
                        "benchmarks-sha": "c" * 40,
                        "benchmarks-dirty": "false",
                        "server-workers": "1",
                    }
                },
                {
                    "config": {
                        "framework-version": "0.24.0-beta",
                        "framework-sha": "b" * 40,
                        "framework-dirty": "true",
                        "benchmarks-sha": "c" * 40,
                        "benchmarks-dirty": "false",
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
            self.assertIn("**Framework dirty** — `true`", markdown)
            self.assertIn("**Benchmarks SHA** — `" + "c" * 40 + "`", markdown)
            self.assertIn("**Mixed source provenance:**", markdown)
            self.assertIn("**Dirty source tree:** framework", markdown)


if __name__ == "__main__":
    unittest.main()
