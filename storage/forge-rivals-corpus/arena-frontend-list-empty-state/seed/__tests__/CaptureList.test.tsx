import { describe, expect, it } from 'vitest'
import { render } from '@testing-library/react'
import { CaptureList } from '../CaptureList'

describe('CaptureList', () => {
  it('renders provided items', () => {
    const { getByText } = render(
      <CaptureList items={[{ id: '1', text: 'primeira' }]} />,
    )
    expect(getByText('primeira')).toBeTruthy()
  })
})
