# Primonizer Forex AI

AI-assisted forex chart analysis for candlestick screenshots.

## First release

- Candlestick screenshot upload: JPG, PNG, WEBP
- Symbol and timeframe selection
- Multimodal AI chart reading
- Market structure analysis
- BOS / CHoCH candidates
- Liquidity and sweep candidates
- Potential order-block zones with context and invalidation
- FVG / imbalance candidates
- Support and resistance
- Premium / discount when visible
- Bullish and bearish scenarios instead of guaranteed signals
- Risk notes
- InfinityFree FTP deployment via GitHub Actions

## AI connection

The PHP endpoint reads `OPENAI_API_KEY` from the server environment and calls the OpenAI Responses API with text + image input. Do not commit an API key to this public repository.

## Forex analysis references

The terminology and educational framework are informed by established trading education resources, including:

- BabyPips Forexpedia — Order Block: https://www.babypips.com/forexpedia/order-block
- BabyPips — Forex Market Structure: https://www.babypips.com/learn/forex/forex-market-structure
- IG — How to Read Forex Charts: https://www.ig.com/en/trading-strategies/how-to-read-forex-charts-200720
- IG — Support and Resistance: https://www.ig.com/en/trading-strategies/what-are-support-and-resistance-levels-in-forex-trading--221109

These concepts are not treated as guarantees. The analyzer should distinguish visible evidence from inference and should encourage validation and risk management.

## Next development stages

1. Add multi-timeframe uploads.
2. Add computer-vision chart geometry and price-axis extraction.
3. Add structured detection objects for swings, zones and FVGs.
4. Add a backtesting/research engine to test discovered patterns instead of assuming they work.
5. Add user accounts and saved analyses.
6. Connect the production site to `primonizer.kesug.com`.
