import { ThemeProvider } from '@automattic/jetpack-components';
import { JetpackEditorPanelLogo } from '@automattic/jetpack-shared-extension-utils';
import { useSelect } from '@wordpress/data';
import { PluginPostPublishPanel } from '@wordpress/edit-post';
import { store as editorStore } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { store as socialStore } from '../../social-store';
import { ManualSharingInfo } from '../manual-sharing/info';
import { ShareButtons } from '../share-buttons/share-buttons';
import styles from './styles.module.scss';
/**
 * Post Publish Manual Sharing component.
 *
 * @return {import('react').ReactNode} Post Publish Manual Sharing component.
 */
export default function PostPublishManualSharing() {
	const { isCurrentPostPublished } = useSelect( select => select( editorStore ), [] );

	const { featureFlags } = useSelect( select => {
		const store = select( socialStore );
		return {
			featureFlags: store.featureFlags(),
		};
	}, [] );

	if ( ! isCurrentPostPublished() ) {
		return null;
	}

	return (
		<PluginPostPublishPanel
			initialOpen
			title={ __( 'Manual sharing', 'jetpack' ) }
			id="publicize-manual-sharing"
			icon={ <JetpackEditorPanelLogo /> }
		>
			<ThemeProvider>
				<ManualSharingInfo className={ styles.description } variant="body-small" />
				<ShareButtons />
				{ featureFlags?.useShareStatus && 'Share status modal comes here' }
			</ThemeProvider>
		</PluginPostPublishPanel>
	);
}
